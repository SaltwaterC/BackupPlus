//go:build js && wasm

package main

import (
	"crypto/rand"
	"encoding/hex"
	"errors"

	"filippo.io/age"
	"syscall/js"
)

const maxKeyBytes = 64 * 1024
const maxStanzas = 100
const maxStanzaBody = 1024

var identities = map[string]importedIdentity{}
var shutdown = make(chan struct{}, 1)
var callbacks []js.Func

func main() {
	api := js.Global().Get("Object").New()
	api.Set("open", bind(open))
	api.Set("unwrap", bind(unwrap))
	api.Set("release", bind(release))
	api.Set("shutdown", bind(stop))
	js.Global().Set("BackupPlusAgeSSH", api)
	<-shutdown
	for handle, identity := range identities {
		clear(identity.native)
		delete(identities, handle)
	}
	for _, callback := range callbacks {
		callback.Release()
	}
}

func bind(fn func(js.Value, []js.Value) any) js.Func {
	callback := js.FuncOf(func(this js.Value, args []js.Value) (out any) {
		// A malformed key or JS argument must not kill the shared worker runtime.
		defer func() {
			if recover() != nil {
				out = result("DECRYPT_FAILED", nil)
			}
		}()
		return fn(this, args)
	})
	callbacks = append(callbacks, callback)
	return callback
}

func open(_ js.Value, args []js.Value) any {
	if len(args) != 2 || args[0].Type() != js.TypeString || args[1].Type() != js.TypeString {
		return result("UNSUPPORTED_KEY", nil)
	}
	identity, code := parseIdentity(args[0].String(), args[1].String())
	if code != "" {
		return result(code, nil)
	}
	var random [16]byte
	if _, err := rand.Read(random[:]); err != nil {
		return result("BRIDGE_ERROR", nil)
	}
	handle := hex.EncodeToString(random[:])
	identities[handle] = identity
	values := map[string]any{"handle": handle}
	if identity.native != nil {
		secret := js.Global().Get("Uint8Array").New(len(identity.native))
		js.CopyBytesToJS(secret, identity.native)
		values["nativeSecret"] = secret
	}
	return result("", values)
}

func unwrap(_ js.Value, args []js.Value) any {
	if len(args) != 2 || args[0].Type() != js.TypeString {
		return result("UNWRAP_FAILED", nil)
	}
	identity, ok := identities[args[0].String()]
	if !ok {
		return result("INVALID_HANDLE", nil)
	}
	stanzas, err := parseStanzas(args[1])
	if err != nil {
		return result("UNWRAP_FAILED", nil)
	}
	if identity.ssh == nil {
		return result("UNWRAP_FAILED", nil)
	}
	fileKey, err := identity.ssh.Unwrap(stanzas)
	if errors.Is(err, age.ErrIncorrectIdentity) {
		return js.ValueOf(map[string]any{"ok": true, "fileKey": nil})
	}
	if err != nil || len(fileKey) != 16 {
		clear(fileKey)
		return result("UNWRAP_FAILED", nil)
	}
	defer clear(fileKey)
	key := js.Global().Get("Uint8Array").New(len(fileKey))
	js.CopyBytesToJS(key, fileKey)
	return js.ValueOf(map[string]any{"ok": true, "fileKey": key})
}

func parseStanzas(value js.Value) ([]*age.Stanza, error) {
	if value.Type() != js.TypeObject || value.IsNull() || !js.Global().Get("Array").Call("isArray", value).Bool() {
		return nil, errors.New("stanzas must be an array")
	}
	if value.Length() > maxStanzas {
		return nil, errors.New("too many stanzas")
	}
	stanzas := make([]*age.Stanza, 0, value.Length())
	for i := 0; i < value.Length(); i++ {
		input := value.Index(i)
		if input.Type() != js.TypeObject || input.IsNull() {
			return nil, errors.New("invalid stanza")
		}
		typValue := input.Get("type")
		if typValue.Type() != js.TypeString {
			return nil, errors.New("invalid stanza type")
		}
		typ := typValue.String()
		if typ != "ssh-rsa" && typ != "ssh-ed25519" {
			continue
		}
		argsValue := input.Get("args")
		if argsValue.Type() != js.TypeObject || argsValue.IsNull() || !js.Global().Get("Array").Call("isArray", argsValue).Bool() {
			return nil, errors.New("invalid stanza arguments")
		}
		if argsValue.Length() > 16 {
			return nil, errors.New("too many stanza arguments")
		}
		args := make([]string, argsValue.Length())
		for j := range args {
			if argsValue.Index(j).Type() != js.TypeString {
				return nil, errors.New("invalid stanza argument")
			}
			args[j] = argsValue.Index(j).String()
		}

		bodyValue := input.Get("body")
		if bodyValue.Type() != js.TypeObject || bodyValue.IsNull() || !bodyValue.InstanceOf(js.Global().Get("Uint8Array")) || bodyValue.Length() > maxStanzaBody {
			return nil, errors.New("invalid stanza body")
		}
		body := make([]byte, bodyValue.Length())
		if js.CopyBytesToGo(body, bodyValue) != len(body) {
			return nil, errors.New("invalid stanza body")
		}
		stanzas = append(stanzas, &age.Stanza{Type: typ, Args: args, Body: body})
	}
	return stanzas, nil
}

func release(_ js.Value, args []js.Value) any {
	if len(args) != 1 || args[0].Type() != js.TypeString {
		return js.ValueOf(false)
	}
	handle := args[0].String()
	if _, ok := identities[handle]; !ok {
		return js.ValueOf(false)
	}
	clear(identities[handle].native)
	delete(identities, handle)
	return js.ValueOf(true)
}

func stop(_ js.Value, _ []js.Value) any {
	select {
	case shutdown <- struct{}{}:
	default:
	}
	return js.ValueOf(true)
}

func result(code string, values map[string]any) js.Value {
	result := map[string]any{"ok": code == ""}
	if code != "" {
		result["error"] = code
	}
	for name, value := range values {
		result[name] = value
	}
	return js.ValueOf(result)
}
