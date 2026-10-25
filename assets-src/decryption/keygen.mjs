import { generateX25519Identity, identityToRecipient } from 'age-encryption';

export async function generateAgeIdentity() {
	const identity = await generateX25519Identity();
	return { identity, recipient: await identityToRecipient(identity) };
}
