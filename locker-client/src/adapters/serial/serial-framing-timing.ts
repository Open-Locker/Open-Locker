export interface SerialFraming {
  baudRate: number;
  dataBits: 7 | 8;
  stopBits: 1 | 2;
  parity: 'none' | 'even' | 'odd';
}

const MODBUS_RTU_HIGH_BAUD_DELAY_MS = 1.75;
export const TIMER_SAFETY_MARGIN_MS = 1;

export function calculateCharacterTimeMs(framing: SerialFraming): number {
  const parityBits = framing.parity === 'none' ? 0 : 1;
  const bitsPerCharacter = 1 + framing.dataBits + parityBits + framing.stopBits;
  return (bitsPerCharacter * 1000) / framing.baudRate;
}

/** Modbus RTU inter-frame / inter-transaction silence (ADR-0035). */
export function calculateInterTransactionDelayMs(framing: SerialFraming): number {
  const specificationDelayMs =
    framing.baudRate > 19_200
      ? MODBUS_RTU_HIGH_BAUD_DELAY_MS
      : 3.5 * calculateCharacterTimeMs(framing);

  return Math.ceil(specificationDelayMs + TIMER_SAFETY_MARGIN_MS);
}

/** Silence after the last response byte before a proprietary frame is considered complete. */
export function calculateInterByteFrameQuietMs(framing: SerialFraming): number {
  return calculateInterTransactionDelayMs(framing);
}
