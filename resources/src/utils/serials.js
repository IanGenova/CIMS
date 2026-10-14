// Serial numbers on a line: one per unit, each once (tests/js/serials.test.js).
//
// The screen-side twin of app/utils/SerialService.php's check, so a cashier
// sees "1 more serial number needed" before the sale is sent, not after.

export function parseSerials(input) {
  if (!input) {
    return [];
  }
  const list = Array.isArray(input) ? input : String(input).split(/[\r\n,]+/);
  return list.map((s) => String(s).trim()).filter((s) => s !== '');
}

export function baseUnits(quantity, unit) {
  const qty = Number(quantity) || 0;
  if (!unit) {
    return qty;
  }
  const value = Number(unit.operator_value) || 1;
  return Math.round((unit.operator === '/' ? qty / value : qty * value) * 1e6) / 1e6;
}

// Base units in one of this unit: a box of 2 is 2, a "/ 4" unit is 0.25.
export function unitFactor(unit) {
  return baseUnits(1, unit);
}

// The units a line needs serials for. Screens keep line.unit_factor in step
// with the line's unit, so a box of 2 phones needs 2 serials, as the server counts.
export function lineUnits(line) {
  const factor = Number(line.unit_factor) || 1;
  return Math.round((Number(line.quantity) || 0) * factor * 1e6) / 1e6;
}

function plural(n, word) {
  return n + ' ' + word + (n === 1 ? '' : 's');
}

export function serialProblem(serials, units, name) {
  const list = parseSerials(serials);
  if (Math.abs(units - Math.round(units)) > 1e-6) {
    return name + ' is counted in whole units, and ' + units + ' is not a whole number.';
  }
  const need = Math.round(units);
  const counts = '(' + plural(need, 'unit') + ', ' + list.length + ' given).';
  if (list.length < need) {
    return name + ': ' + plural(need - list.length, 'more serial number') + ' needed ' + counts;
  }
  if (list.length > need) {
    return name + ': ' + plural(list.length - need, 'serial number') + ' too many ' + counts;
  }
  const seen = {};
  for (const s of list) {
    const key = s.toUpperCase();
    if (seen[key]) {
      return name + ': serial number ' + s + ' is listed twice.';
    }
    seen[key] = true;
  }
  return null;
}

// unitOf(line) gives the line's unit ({operator, operator_value}); without it
// the line's own unit_factor is used (see lineUnits).
export function firstSerialProblem(lines, unitOf) {
  for (const line of lines || []) {
    if (!line.has_serials) {
      continue;
    }
    const units = unitOf ? baseUnits(line.quantity, unitOf(line)) : lineUnits(line);
    const problem = serialProblem(line.serials, units, line.name);
    if (problem) {
      return problem;
    }
  }
  return null;
}
