// What the sign-in page does with the server's answer (tests/js/signInFlow.test.js).

// After the password: 'code' when two-factor is on (nobody is signed in yet),
// otherwise 'signed_in'.
export function afterPassword(data) {
  return data && data.two_factor_required ? 'code' : 'signed_in';
}

// The reason a sign-in was refused, in the server's own words when it gave
// any (a locked account, a wrong code), else the fallback.
export function refusal(body, fallback) {
  if (body && body.errors) {
    const first = Object.values(body.errors).find(list => list && list.length);
    if (first) {
      return first[0];
    }
  }
  if (body && body.message && body.message !== 'The given data was invalid.') {
    return body.message;
  }
  return fallback;
}
