// A line's price when its unit changes (tests/js/unitPrice.test.js).
//
// Units are defined against a base unit: "*" 10 means one of these is ten
// base units, "/" 1000 means one is a thousandth of the base. The price per
// base unit is kept, so a strip of 10 costs ten tablets and a box of 100 a
// hundred -- including a price the cashier had already typed.

function baseUnitsIn(unit) {
  const value = Number(unit.operator_value) || 1;
  return unit.operator === '/' ? 1 / value : value;
}

export function repriceForUnit(price, fromUnit, toUnit) {
  const amount = Number(price) || 0;
  if (!fromUnit || !toUnit) {
    return amount;
  }
  const scaled = amount / baseUnitsIn(fromUnit) * baseUnitsIn(toUnit);
  return Math.round(scaled * 100) / 100;
}
