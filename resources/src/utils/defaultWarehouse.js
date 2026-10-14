// The warehouse a new transaction starts in.
//
// Screens are sent only the warehouses their user may work in, so a cashier
// tied to one branch gets a list of one: start there instead of asking every
// time. With several, the shop's default is used when this person may work in
// it; otherwise they choose. Something already chosen is never replaced.
// Returns the warehouse id to select, or null to leave it alone.
// See tests/js/defaultWarehouse.test.js.
export function startingWarehouse(warehouses, current, shopDefault) {
  if (current !== '' && current !== null && current !== undefined) {
    return null;
  }
  const list = warehouses || [];
  if (list.length === 1) {
    return list[0].id;
  }
  if (shopDefault && list.some(w => w.id === shopDefault)) {
    return shopDefault;
  }
  return null;
}
