import assert from "node:assert/strict";
import test from "node:test";
import { mqttCredentialsFileSchema } from "../services/credentialsService";

test("mqttCredentialsFileSchema accepts non-empty username and password", () => {
  const ok = mqttCredentialsFileSchema.safeParse({
    username: "locker-uuid",
    password: "secret",
  });
  assert.equal(ok.success, true);
  if (ok.success) {
    assert.equal(ok.data.username, "locker-uuid");
    assert.equal(ok.data.password, "secret");
  }
});

test("mqttCredentialsFileSchema rejects empty username", () => {
  const bad = mqttCredentialsFileSchema.safeParse({
    username: "",
    password: "x",
  });
  assert.equal(bad.success, false);
});

test("mqttCredentialsFileSchema rejects missing fields", () => {
  const bad = mqttCredentialsFileSchema.safeParse({});
  assert.equal(bad.success, false);
});

test("mqttCredentialsFileSchema rejects non-strings", () => {
  const bad = mqttCredentialsFileSchema.safeParse({
    username: 1,
    password: "x",
  });
  assert.equal(bad.success, false);
});
