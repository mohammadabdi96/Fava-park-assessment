/*
 * Runs assets/js/scoring.js (the copy used in the judge's browser) on the cases the PHP
 * test wrote, so both implementations can be compared.
 *   node judge.js <cases.json>   → JSON array of results on stdout
 */
'use strict';
var fs = require('fs');
var path = require('path');
var S = require(path.join(__dirname, '..', '..', 'assets', 'js', 'scoring.js'));
var cases = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
var out = cases.map(function (c) {
  var m = S.judge(c.cfg, c.answers, c.fixed);
  return {
    judge: m,
    amount: c.amount ? S.amount(c.amount[0], c.amount[1], c.amount[2], c.amount[3], c.cfg) : null,
    decision: c.total !== undefined ? S.decision(c.total, c.cfg) : null
  };
});
process.stdout.write(JSON.stringify(out));
