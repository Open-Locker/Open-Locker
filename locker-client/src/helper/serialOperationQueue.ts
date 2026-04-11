export type QueueErrorHandler = (
  operationName: string,
  error: unknown,
) => void;

export class SerialOperationQueue {
  private queue: Promise<void> = Promise.resolve();

  constructor(private readonly onOperationFailure?: QueueErrorHandler) {}

  enqueue<T>(
    operationName: string,
    operation: () => Promise<T>,
  ): Promise<T> {
    const runOperation = this.queue.then(operation, operation);

    this.queue = runOperation.then(
      () => undefined,
      (error) => {
        this.onOperationFailure?.(operationName, error);
      },
    );

    return runOperation;
  }
}
