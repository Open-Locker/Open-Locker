const REGISTRATION_PREFIX = 'locker/register/';

export const REDACTED = '[redacted]';

/**
 * Keeps the provisioning token out of the logs.
 *
 * A device registers on `locker/register/<provisioning token>`, so the topic
 * *is* the credential — logging it raw leaks it. Nothing of the token survives,
 * not even a prefix, since a partial token still shrinks the search space for
 * anyone brute-forcing it.
 *
 * Mirrors `MqttTopicRedactor` on the backend.
 */
export function redactTopic(topic: string): string {
  if (!topic.startsWith(REGISTRATION_PREFIX)) {
    return topic;
  }

  return `${REGISTRATION_PREFIX}${REDACTED}`;
}
