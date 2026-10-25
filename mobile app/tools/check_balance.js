#!/usr/bin/env node
/**
 * Bracket/string/comment-aware balance checker for Dart files.
 * Usage: node tools/check_balance.js file1.dart file2.dart ...
 * Exit 0 = all balanced; exit 1 = problems (printed).
 */
const fs = require('fs');

function checkFile(path) {
  const src = fs.readFileSync(path, 'utf8');
  const stack = [];
  const pairs = { ')': '(', ']': '[', '}': '{' };
  let state = 'code'; // code | lineComment | blockComment | string | stringRaw
  let quote = '';
  let line = 1;
  const errors = [];

  for (let i = 0; i < src.length; i++) {
    const c = src[i];
    const n = src[i + 1];
    if (c === '\n') line++;

    switch (state) {
      case 'code':
        if (c === '/' && n === '/') { state = 'lineComment'; i++; }
        else if (c === '/' && n === '*') { state = 'blockComment'; i++; }
        else if (c === "'" || c === '"') {
          if (src[i + 1] === c && src[i + 2] === c) { state = 'string'; quote = c.repeat(3); i += 2; }
          else { state = 'string'; quote = c; }
        } else if (c === 'r' && (n === "'" || n === '"')) {
          if (src[i + 1] === n && src[i + 2] === n) { state = 'stringRaw'; quote = n.repeat(3); i += 2; }
          else { state = 'stringRaw'; quote = n; i++; }
        } else if (c === '(' || c === '[' || c === '{') stack.push({ ch: c, line });
        else if (c === ')' || c === ']' || c === '}') {
          const top = stack.pop();
          if (!top || top.ch !== pairs[c]) {
            errors.push(`line ${line}: unexpected '${c}' (opened at line ${top ? top.line : 'nothing'})`);
            if (top === undefined) return errors; // unrecoverable
          }
        }
        break;
      case 'lineComment':
        if (c === '\n') state = 'code';
        break;
      case 'blockComment':
        if (c === '*' && n === '/') { state = 'code'; i++; }
        break;
      case 'string':
        if (c === '\\') { i++; }
        else if (quote.length === 3 ? (c === quote[0] && src[i + 1] === quote[0] && src[i + 2] === quote[0]) : c === quote) {
          if (quote.length === 3) i += 2;
          state = 'code';
        }
        break;
      case 'stringRaw':
        if (quote.length === 3 ? (c === quote[0] && src[i + 1] === quote[0] && src[i + 2] === quote[0]) : c === quote) {
          if (quote.length === 3) i += 2;
          state = 'code';
        }
        break;
    }
  }
  if (state === 'blockComment') errors.push('EOF inside block comment');
  if (state === 'string' || state === 'stringRaw') errors.push(`EOF inside string (${quote})`);
  for (const open of stack) errors.push(`line ${open.line}: '${open.ch}' never closed`);
  return errors;
}

const files = process.argv.slice(2);
if (!files.length) { console.log('usage: node tools/check_balance.js <dart files>'); process.exit(1); }
let bad = 0;
for (const f of files) {
  if (!fs.existsSync(f)) { console.log(`MISSING ${f}`); bad++; continue; }
  const errors = checkFile(f);
  if (errors.length) { bad++; console.log(`FAIL ${f}`); for (const e of errors) console.log(`   ${e}`); }
  else console.log(`OK   ${f}`);
}
process.exit(bad ? 1 : 0);
