/**
 * Whether a refusal came from the terms gate.
 *
 * Matched on the code the middleware sends, never on the message: the message is
 * localised and free to be reworded, and a 403 that merely mentions terms is not
 * the same thing as the gate refusing.
 */
export function isTermsNotAcceptedError(data: unknown): boolean {
  return (
    typeof data === 'object' &&
    data !== null &&
    (data as { code?: unknown }).code === 'terms_not_accepted'
  );
}
