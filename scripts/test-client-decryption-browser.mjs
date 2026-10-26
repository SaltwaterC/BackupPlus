/**
 * Real Chromium smoke/integration checks against the local WordPress Decryption tab.
 *
 * This is the entry point; the pieces live in client-decryption-browser/. The environment (Chromium,
 * the recording proxy, the WordPress fixture commands) is started first and its state is carried in one
 * context object that the DevTools connection, the shared helpers and the scenarios add to and read from.
 * The scenarios run in order and share one browser session, so each one relies on the page state the
 * previous one left behind.
 */
import { rm } from 'node:fs/promises';
import { startEnvironment } from './client-decryption-browser/environment.mjs';
import { connect } from './client-decryption-browser/devtools.mjs';
import { createHelpers } from './client-decryption-browser/helpers.mjs';
import {
	checkTabMarkup,
	decryptVectors,
	cancellationAndStorageFailures,
	fallbacksAndLimits,
} from './client-decryption-browser/scenarios/decrypt.mjs';
import { auditRequestsAndLayout, auditLogs } from './client-decryption-browser/scenarios/audit.mjs';
import { restoreScreen } from './client-decryption-browser/scenarios/restore-screen.mjs';
import { keygenSettingsScreen } from './client-decryption-browser/scenarios/keygen-settings.mjs';

const ctx = await startEnvironment();
try {
	await connect(ctx);
	await checkTabMarkup(ctx);
	await createHelpers(ctx);
	ctx.networkStart = ctx.requests.length;
	await decryptVectors(ctx);
	await cancellationAndStorageFailures(ctx);
	await fallbacksAndLimits(ctx);
	await auditRequestsAndLayout(ctx);
	await restoreScreen(ctx);
	await keygenSettingsScreen(ctx);
	await auditLogs(ctx);
	await Promise.race([ctx.call('Browser.close'), ctx.sleep(1000)]);
} finally {
	if (ctx.restoreIsolated) ctx.restoreFixture('cleanup');
	if (ctx.keygenIsolated) ctx.keygenSettings('cleanup');
	if (ctx.ws) ctx.ws.close();
	ctx.browser.kill();
	await ctx.browserExited;
	ctx.proxy.closeAllConnections();
	await new Promise((resolve) => ctx.proxy.close(resolve));
	await rm(ctx.directory, { recursive: true, force: true, maxRetries: 5, retryDelay: 100 });
}
