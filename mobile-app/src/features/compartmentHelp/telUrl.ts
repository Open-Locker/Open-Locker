/** Dialers reject spaces, slashes and brackets that people type into phone numbers. */
export function toTelUrl(phone: string): string {
  return `tel:${phone.replace(/[^\d+]/g, '')}`;
}
