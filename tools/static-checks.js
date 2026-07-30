#!/usr/bin/env node
/*
 * GASF-CRM static checks — tools/static-checks.js
 *
 * The half of the test suite that needs no WordPress: artifact sweeps, route
 * hygiene, SQL hygiene, and the house copy rule. Runs in CI on every push and
 * locally before every commit, alongside check-js.js (which owns inline
 * JS/CSS parsing).
 *
 * Every check here is the codified form of a defect that actually shipped:
 *   - Placeholder tokens and — escapes are the residue of patch scripts
 *     leaking their scaffolding into source. It happened four times.
 *   - A REST route without a permission_callback is how WordPress publishes
 *     an endpoint to the world with a deprecation notice as the only guard.
 *   - The serial-comma rule is the house style for everything a member reads.
 *
 * Regexes that carry backslash classes are built with new RegExp from plain
 * strings: a literal backslash-b once travelled through a patch script as the
 * BACKSPACE character and disabled its own check invisibly. Strings survive
 * tooling; escape sequences do not.
 */
'use strict';

const fs = require('fs');
const path = require('path');

const ROOT = path.join(__dirname, '..');
const phpFiles = [];
(function walk(dir) {
	for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
		if (e.name === 'node_modules' || e.name === '.git') continue;
		const p = path.join(dir, e.name);
		if (e.isDirectory()) walk(p);
		else if (e.name.endsWith('.php')) phpFiles.push(p);
	}
}(ROOT));

let failures = 0;
const fail = (file, line, what) => {
	failures++;
	console.log(`  FAIL  ${path.relative(ROOT, file)}:${line}  ${what}`);
};

// "password, token, or session" is a list; "if this persists, check the
// recycle bin and permissions" is a clause. Openers below start clauses.
const CLAUSE_OPENER = new RegExp('^(but|so|which|that|if|because|while|where|who|then|check|reply)([ ]|$)');
const LIST_SHAPE    = new RegExp("[\\w'’)]+, ((?:[\\w’']+ ){0,2}[\\w’']+) (and|or) ");
const PLACEHOLDER   = '@@' + 'D' + '@@'; // assembled so this file passes its own sweep
const ESC_LEAK      = new RegExp('\\\\u20(14|13)');

for (const file of phpFiles) {
	const src = fs.readFileSync(file, 'utf8');
	const lines = src.split(/\r?\n/);

	lines.forEach((l, i) => {
		// 1. Patch-script residue. A literal ellipsis escape inside a JS
		//    string is legitimate (JS interprets it); the dashes never are.
		if (l.includes(PLACEHOLDER)) fail(file, i + 1, 'placeholder token leaked into source');
		const esc = l.match(ESC_LEAK);
		if (esc) fail(file, i + 1, 'literal \\u20' + esc[1] + ' escape leaked into source');

		// 2. The house copy rule: a three-item list in user-facing copy takes
		//    a comma before the final and/or. Only echo/printf strings; code
		//    comments are not site copy.
		const t = l.trim();
		if ((/^(echo|printf|return sprintf|\. ')/.test(t) || /=> '/.test(t)) && !t.startsWith('//') && !t.startsWith('*')) {
			const m = t.match(LIST_SHAPE);
			if (m && !CLAUSE_OPENER.test(m[1])) {
				fail(file, i + 1, 'possible missing serial comma: "' + m[0].trim() + '"');
			}
		}
	});

	// 3. Every REST route carries a permission_callback.
	const routeRe = /register_rest_route\(/g;
	let rm;
	while ((rm = routeRe.exec(src)) !== null) {
		const slice = src.slice(rm.index, rm.index + 600);
		if (!slice.includes('permission_callback')) {
			const line = src.slice(0, rm.index).split(/\r?\n/).length;
			fail(file, line, 'register_rest_route without a permission_callback in reach');
		}
	}

	// 4. SQL with a variable in the VALUE position of a predicate and no
	//    prepare(). Interpolating an internal table name after FROM/JOIN is
	//    the house pattern and safe; a phpcs:ignore is a human's explicit
	//    sign-off and is honoured.
	const sqlRe = /->\s*(get_results|get_var|get_row|get_col|query)\s*\(/g;
	let sm;
	while ((sm = sqlRe.exec(src)) !== null) {
		const end = src.indexOf(';', sm.index);
		const stmt = src.slice(sm.index, end === -1 ? sm.index + 400 : end);
		const valuePos = /(=|LIKE|VALUES?\s*\()[^;]{0,50}(\{\$(?!wpdb)|'\s*\.\s*\$|"\s*\.\s*\$)/.test(stmt);
		if (valuePos && !stmt.includes('prepare(') && !stmt.includes('phpcs:ignore WordPress.DB')) {
			const line = src.slice(0, sm.index).split(/\r?\n/).length;
			fail(file, line, 'SQL with variables and no prepare()');
		}
	}
}

console.log(failures
	? '\nstatic checks: ' + failures + ' failure(s)'
	: '  ✓ static checks clean across ' + phpFiles.length + ' PHP file(s)');
process.exit(failures ? 1 : 0);
