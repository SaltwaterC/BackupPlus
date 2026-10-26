// Minifies the readable stylesheets in css/ into the stable build inputs in assets-src/css/. scripts/build-assets.php
// copies those to the versioned names the plugin enqueues. css/backupplus-admin.css only @imports the partials in
// css/admin/, so the stylesheets are bundled and not just transformed. css/backupplus-tour.scss is not handled here:
// its minified file is compiled from SCSS, which needs a Sass compiler.
import * as esbuild from 'esbuild-wasm';
import { readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const names = ['backupplus-admin', 'backupplus-deactivation', 'backupplus-notices'];
const check = process.argv.includes('--check');
const stale = [];
// A url() to a file next to the stylesheets is kept external, rebased from the partial that names it to the output
// file, which sits in css/ like the entry file.
const keepExternalFiles = {
	name: 'keep-external-files',
	setup(build) {
		build.onResolve({ filter: /\.(?:svg|png|jpe?g|gif|webp|woff2?|ttf|eot)$/ }, (args) => ({
			path: path.relative(path.join(root, 'css'), path.resolve(args.resolveDir, args.path)).split(path.sep).join('/'),
			external: true,
		}));
	},
};
for (const name of names) {
	const outfile = path.join(root, 'css', name + '.min.css'); // never written: the sources in the map are relative to css/
	const result = await esbuild.build({
		entryPoints: [path.join(root, 'css', name + '.css')],
		outfile,
		bundle: true,
		minify: true,
		sourcemap: 'external',
		sourcesContent: false,
		legalComments: 'none',
		write: false,
		plugins: [keepExternalFiles],
	});
	const output = (suffix) => result.outputFiles.find((file) => file.path.endsWith(suffix)).text;
	const outputs = {
		['assets-src/css/' + name + '.min.css']: output('.css') + '/*# sourceMappingURL=' + name + '.min.css.map */\n',
		['assets-src/css/' + name + '.min.css.map']: output('.css.map'),
	};
	for (const [relative, content] of Object.entries(outputs)) {
		const file = path.join(root, relative);
		if (check) {
			if ((await readFile(file, 'utf8').catch(() => '')) !== content) stale.push(relative);
		} else await writeFile(file, content);
	}
}
esbuild.stop();
if (stale.length) throw new Error('Stale minified CSS (' + stale.join(', ') + '): run make build-assets');
console.log((check ? 'Minified CSS is current for ' : 'Built minified CSS for ') + names.join(', ') + '.');
