import type { FeedbackType } from './config';
import type { DoorState } from './compartment';

export function doorStateFromFeedbackSignal(
  feedbackType: FeedbackType,
  signalHigh: boolean,
): Exclude<DoorState, 'unknown'> {
  const closed = feedbackType === 'door_closing' ? signalHigh : !signalHigh;
  return closed ? 'closed' : 'open';
}
