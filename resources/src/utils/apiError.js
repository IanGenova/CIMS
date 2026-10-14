// What a failed request rejects with.
//
// The body itself, as the interceptor always did, so screens that read
// error.message / error.errors keep working -- and the same body also carries
// axios's own `response`, so the 24 screens written for error.response.data
// stop showing "Invalid Data !!" in place of the server's reason. The added
// field is not enumerable, so the body serialises exactly as before.
// See tests/js/apiError.test.js.
export function rejection(error) {
  if (error && error.response && error.response.data) {
    const body = error.response.data;
    if (body !== null && typeof body === "object" && !("response" in body)) {
      Object.defineProperty(body, "response", {
        value: error.response,
        enumerable: false,
        configurable: true
      });
    }
    return body;
  }
  return error && error.message;
}
