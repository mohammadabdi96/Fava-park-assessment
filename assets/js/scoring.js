/*!
 * Fava assessment – scoring rules (browser + Node).
 * Mirror of app/Domain/Scoring.php::judge(). The server recomputes everything on
 * submit; this copy only gives the judge live feedback while filling in the form.
 */
(function (root, factory) {
  if (typeof module === 'object' && module.exports) { module.exports = factory(); }
  else { root.FavaScoring = factory(); }
})(typeof self !== 'undefined' ? self : this, function () {
  'use strict';

  /*
   * Rounding (half up) with exactly the expression of Scoring::round() in PHP, so that
   * both sides arrive at the same double for the same input.
   */
  function round(value, decimals) {
    var f = Math.pow(10, decimals);
    return Math.floor(Number(value) * f + 0.5 + 1e-9) / f;
  }

  function r6(v) { return round(v, 6); }

  function tier(scalability, priority) {
    if (!scalability || !priority) return null;
    if (scalability === 'high' && priority === 'high') return 'A';
    if (scalability === 'low' && priority === 'normal') return 'C';
    return 'B';
  }

  /* TRL 1–3 → 1, 4–6 → 3, 7–9 → 5 */
  function trlRisk(trl) {
    trl = parseInt(trl, 10);
    if (isNaN(trl) || trl < 1 || trl > 9) return null;
    return trl <= 3 ? 1 : (trl <= 6 ? 3 : 5);
  }

  function riskValue(v) {
    if (v === null || v === undefined || v === '') return null;
    v = parseInt(v, 10);
    return (v === 1 || v === 3 || v === 5) ? v : null;
  }

  function coefficient(riskAvg, cfg) {
    return r6(cfg.risk.base + (riskAvg - 1) * cfg.risk.step);
  }

  function decision(total, cfg) {
    total = r6(total);
    if (total < cfg.thresholds.reject_below) return 'reject';
    if (total < cfg.thresholds.conditional_below) return 'conditional';
    return 'approve';
  }

  function amount(requested, ceiling, total, coeff, cfg) {
    var base = Math.min(requested, ceiling);
    if (decision(total, cfg) === 'reject') return { base: base, raw: 0, amount: 0, capped: false };
    var raw = r6(base * (total / 100) * coeff);
    var capped = !!cfg.cap_amount && raw > base;
    return { base: base, raw: raw, amount: capped ? base : raw, capped: capped };
  }

  /**
   * @param {object} cfg      client config: {role, dims:{dim:{criteria:[ids]}}, weights, thresholds, tiers, risk, cap_amount}
   * @param {object} answers  {scalability, priority, scores:{id:1..5}, trl, risk_exec, risk_market, requested_amount}
   * @param {number|null} fixedAmount requested amount set by the admin
   */
  function judge(cfg, answers, fixedAmount) {
    var dims = {}, complete = true, share = 0, weightSum = 0;
    Object.keys(cfg.dims).forEach(function (dim) {
      var ids = cfg.dims[dim].criteria, sum = 0, filled = 0;
      ids.forEach(function (id) {
        var v = answers.scores && answers.scores[id] ? parseInt(answers.scores[id], 10) : 0;
        if (v >= 1 && v <= 5) { sum += v; filled++; }
      });
      var dimComplete = filled === ids.length;
      var avg = ids.length ? sum / ids.length : 0;
      var weight = Number(cfg.weights[dim]);
      dims[dim] = { avg: r6(avg), complete: dimComplete, weight: weight, points: r6(avg * weight * 20), max: r6(weight * 100) };
      complete = complete && dimComplete;
      share += avg * weight * 20;
      weightSum += weight;
    });
    share = r6(share);
    var shareMax = r6(weightSum * 100);
    var estimatedTotal = (complete && weightSum > 0) ? r6(share / weightSum) : null;
    var estimatedDecision = estimatedTotal !== null ? decision(estimatedTotal, cfg) : null;

    var t = tier(answers.scalability, answers.priority);
    var ceiling = t ? Number(cfg.tiers[t].ceiling) : null;

    var trl = null, tRisk = null, riskExec = null, riskMarket = null, riskAvg = null;
    if (cfg.role === 'tech') {
      trl = (answers.trl !== null && answers.trl !== undefined && answers.trl !== '') ? parseInt(answers.trl, 10) : null;
      tRisk = trl !== null ? trlRisk(trl) : null;
      riskExec = riskValue(answers.risk_exec);
      if (tRisk !== null && riskExec !== null) riskAvg = (tRisk + riskExec) / 2;
    } else {
      riskMarket = riskValue(answers.risk_market);
      if (riskMarket !== null) riskAvg = riskMarket;
    }
    var coeff = riskAvg !== null ? coefficient(riskAvg, cfg) : null;

    var requested = (fixedAmount !== null && fixedAmount !== undefined) ? Number(fixedAmount)
      : ((answers.requested_amount !== null && answers.requested_amount !== undefined && answers.requested_amount !== '') ? Number(answers.requested_amount) : null);
    var estimate = null;
    if (estimatedTotal !== null && requested !== null && requested > 0 && ceiling !== null && coeff !== null) {
      estimate = amount(requested, ceiling, estimatedTotal, coeff, cfg).amount;
    }

    return {
      role: cfg.role, dims: dims, complete: complete, share: share, share_max: shareMax,
      estimated_total: estimatedTotal, estimated_decision: estimatedDecision,
      tier: t, ceiling: ceiling,
      trl: trl, trl_risk: tRisk, risk_exec: riskExec, risk_market: riskMarket,
      risk_avg: riskAvg !== null ? r6(riskAvg) : null, coeff: coeff,
      requested: requested, estimate: estimate
    };
  }

  return { tier: tier, trlRisk: trlRisk, coefficient: coefficient, decision: decision, amount: amount, judge: judge, round: round, r6: r6 };
});
