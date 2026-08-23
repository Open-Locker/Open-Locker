export interface RuntimeEnvironment {
  APP_ENV?: string;
  NODE_ENV?: string;
}

export function assertSecureProductionMqttUrl(
  brokerUrl: string,
  environment: RuntimeEnvironment = process.env,
): void {
  const isProduction = [environment.APP_ENV, environment.NODE_ENV].some((value) => {
    const normalized = (value ?? '').trim().toLowerCase();
    return normalized === 'production' || normalized === 'prod';
  });

  if (isProduction && !brokerUrl.toLowerCase().startsWith('mqtts://')) {
    throw new Error('Production MQTT_BROKER_URL must use mqtts://');
  }
}
