"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const strict_1 = __importDefault(require("node:assert/strict"));
const node_test_1 = require("node:test");
const inbound_protocol_guard_1 = require("../../src/adapters/mqtt/inbound-protocol-guard");
const dedup_store_1 = require("../../src/adapters/mqtt/dedup-store");
(0, node_test_1.test)("InboundProtocolGuard rejects missing message_id", () => {
    const guard = new inbound_protocol_guard_1.InboundProtocolGuard(new dedup_store_1.InMemoryDedupStore());
    strict_1.default.equal(guard.allow({ action: "open_compartment", transaction_id: "tx-1" }), false);
});
(0, node_test_1.test)("InboundProtocolGuard rejects missing transaction_id when required", () => {
    const guard = new inbound_protocol_guard_1.InboundProtocolGuard(new dedup_store_1.InMemoryDedupStore());
    strict_1.default.equal(guard.allow({ action: "open_compartment", message_id: "msg-1" }), false);
});
(0, node_test_1.test)("InboundProtocolGuard rejects duplicate message_id", () => {
    const dedup = new dedup_store_1.InMemoryDedupStore();
    const guard = new inbound_protocol_guard_1.InboundProtocolGuard(dedup);
    const payload = {
        action: "open_compartment",
        message_id: "msg-dup",
        transaction_id: "tx-1",
    };
    strict_1.default.equal(guard.allow(payload), true);
    strict_1.default.equal(guard.allow(payload), false);
});
(0, node_test_1.test)("InboundProtocolGuard allows duplicate message_id when blocking disabled", () => {
    const dedup = new dedup_store_1.InMemoryDedupStore();
    const guard = new inbound_protocol_guard_1.InboundProtocolGuard(dedup);
    const payload = {
        action: "snapshot",
        message_id: "msg-retained",
    };
    strict_1.default.equal(guard.allow(payload, {
        requiresTransactionId: false,
        blockDuplicateMessageIds: false,
    }), true);
    strict_1.default.equal(guard.allow(payload, {
        requiresTransactionId: false,
        blockDuplicateMessageIds: false,
    }), true);
});
