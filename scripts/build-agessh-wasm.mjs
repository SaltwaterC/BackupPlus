import { execFileSync } from 'node:child_process';
import { readFile, writeFile, mkdir, mkdtemp, rm } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { gzipSync } from 'node:zlib';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const source = path.join(root, 'assets-src/decryption/agessh');
const output = path.join(root, 'js');
const wasmPath = path.join(output, 'backupplus-agessh.wasm');
const runtimePath = path.join(output, 'backupplus-agessh-wasm_exec.js');
const compressedPath = wasmPath + '.gz';
const licensePath = path.join(output, 'backupplus-agessh-LICENSE.txt');
const check = process.argv.includes('--check');
const toolchain = (await readFile(path.join(source, 'go.mod'), 'utf8')).match(
	/^toolchain (go\S+)$/m,
)?.[1];
if (!toolchain) throw new Error('Pin the Go toolchain in agessh/go.mod.');
const podman = process.env.PODMAN || 'podman';
const containerImage = `localhost/backupplus-agessh-builder:${toolchain}`;

function run(command, args, options = {}) {
	return execFileSync(command, args, { stdio: 'inherit', ...options });
}

function runPodman(args) {
	try {
		run(podman, args);
	} catch (error) {
		if (error.code === 'ENOENT' && error.path === podman)
			throw new Error('Go is not installed and Podman is required to build agessh WASM.');
		throw error;
	}
}

function hasGo() {
	try {
		execFileSync(process.env.GO || 'go', ['version'], {
			env: { ...process.env, GOTOOLCHAIN: toolchain },
			stdio: 'ignore',
		});
		return true;
	} catch (error) {
		if (error.code === 'ENOENT') return false;
		throw error;
	}
}

if (!hasGo()) {
	const version = toolchain.replace(/^go/, '');
	const containerfile = path.join(root, 'dev/agessh-wasm/Containerfile');
	const containerContext = path.dirname(containerfile);
	const cache = path.join(root, '.cache/agessh-go');
	await mkdir(path.join(cache, 'home'), { recursive: true });
	await mkdir(path.join(cache, 'build'), { recursive: true });
	await mkdir(path.join(cache, 'mod'), { recursive: true });
	let hostCaBundle;
	for (const candidate of [
		process.env.SSL_CERT_FILE,
		'/etc/ssl/certs/ca-certificates.crt',
		'/etc/pki/tls/certs/ca-bundle.crt',
		'/etc/ssl/ca-bundle.pem',
	].filter(Boolean)) {
		hostCaBundle = await readFile(candidate).catch(() => undefined);
		if (hostCaBundle) break;
	}
	if (hostCaBundle) await writeFile(path.join(cache, 'host-ca-certificates.pem'), hostCaBundle);
	console.log(`Go ${version} is not installed; building agessh WASM with Podman.`);
	runPodman([
		'build',
		'--build-arg', `GO_VERSION=${version}`,
		'--tag', containerImage,
		'--file', containerfile,
		containerContext,
	]);
	const args = ['run', '--rm'];
	args.push(
		'--volume', `${root}:/workspace:rw,z`,
		'--volume', `${cache}:/go-cache:rw,z`,
		'--workdir', '/workspace',
		'--env', 'HOME=/go-cache/home',
		'--env', 'GOCACHE=/go-cache/build',
		'--env', 'GOMODCACHE=/go-cache/mod',
	);
	if (hostCaBundle) args.push('--env', 'SSL_CERT_FILE=/go-cache/host-ca-certificates.pem');
	for (const variable of [
		'HTTP_PROXY', 'HTTPS_PROXY', 'NO_PROXY',
		'http_proxy', 'https_proxy', 'no_proxy',
		'GOPROXY', 'GOSUMDB', 'GONOSUMDB',
	]) {
		if (process.env[variable] !== undefined) args.push('--env', variable);
	}
	args.push(containerImage, 'node', 'scripts/build-agessh-wasm.mjs', ...(check ? ['--check'] : []));
	runPodman(args);
	process.exit(0);
}

const buildDirectory = await mkdtemp(path.join(os.tmpdir(), 'backupplus-agessh-'));
const builtWasm = path.join(buildDirectory, 'backupplus-agessh.wasm');

function runGo(args, env = process.env) {
	try {
		return execFileSync(process.env.GO || 'go', args, {
			cwd: source,
			env: { ...env, GOTOOLCHAIN: toolchain },
			encoding: 'utf8',
		}).trim();
	} catch (error) {
		if (error.code === 'ENOENT')
			throw new Error('Go is required to build agessh WASM (GOOS=js GOARCH=wasm).');
		throw error;
	}
}

try {
	const env = { ...process.env, GOOS: 'js', GOARCH: 'wasm', CGO_ENABLED: '0' };
	runGo(
		[
			'build',
			'-mod=readonly',
			'-buildvcs=false',
			'-trimpath',
			'-ldflags=-s -w',
			'-o',
			builtWasm,
			'.',
		],
		env,
	);
	const goroot = runGo(['env', 'GOROOT']);
	const goVersion = runGo(['version']);
	const runtimeCandidates = [
		path.join(goroot, 'lib/wasm/wasm_exec.js'),
		path.join(goroot, 'misc/wasm/wasm_exec.js'),
	];
	let runtime;
	for (const candidate of runtimeCandidates) {
		runtime = await readFile(candidate).catch(() => undefined);
		if (runtime) break;
	}
	if (!runtime) throw new Error('Could not find wasm_exec.js in the installed Go toolchain.');
	const wasm = await readFile(builtWasm);
	let licenses = `BackupPlus upstream age SSH/private-key WebAssembly bridge\n\nGo runtime: ${goVersion}\n\n`;
	licenses += await readFile(path.join(goroot, 'LICENSE'), 'utf8');
	const modules = runGo(
		['list', '-deps', '-f', '{{if .Module}}{{.Module.Path}}\t{{.Module.Dir}}{{end}}', '.'],
		env,
	);
	const licensesByModule = new Map();
	for (const line of modules.split('\n').filter(Boolean)) {
		const [name, directory] = line.split('\t');
		if (!directory || name.startsWith('backupplus/')) continue;
		if (licensesByModule.has(name)) continue;
		let license;
		for (const file of ['LICENSE', 'LICENSE.txt', 'LICENSE.md', 'COPYING']) {
			license = await readFile(path.join(directory, file), 'utf8').catch(() => undefined);
			if (license) break;
		}
		if (!license) throw new Error(`Missing Go dependency license: ${name}`);
		licensesByModule.set(name, license);
	}
	for (const [name, license] of licensesByModule) licenses += `\n\n${name}\n${license}`;
	if (check) {
		for (const [file, expected] of [
			[wasmPath, wasm],
			[compressedPath, gzipSync(wasm)],
			[runtimePath, runtime],
			[licensePath, Buffer.from(licenses)],
		]) {
			const actual = await readFile(file).catch(() => undefined);
			if (!actual || !actual.equals(expected))
				throw new Error(
					`Stale agessh WASM artifact: run npm run build:agessh (${path.basename(file)}).`,
				);
		}
	} else {
		await mkdir(output, { recursive: true });
		await Promise.all([
			writeFile(wasmPath, wasm),
			writeFile(compressedPath, gzipSync(wasm)),
			writeFile(runtimePath, runtime),
			writeFile(licensePath, licenses),
		]);
	}
	console.log(
		`agessh WASM ${check ? 'verified' : 'built'}: ${wasm.length} byte module, ${runtime.length} byte Go runtime`,
	);
} finally {
	await rm(buildDirectory, { recursive: true, force: true });
}
