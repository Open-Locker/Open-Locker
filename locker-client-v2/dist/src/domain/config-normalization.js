"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.normalizeCompartments = normalizeCompartments;
exports.computeAppliedConfigHash = computeAppliedConfigHash;
const crypto_1 = require("crypto");
function normalizeCompartments(compartments) {
    return [...compartments]
        .map((c) => ({
        compartment_number: c.compartment_number,
        slaveId: c.slaveId,
        address: c.address,
    }))
        .toSorted((a, b) => a.compartment_number - b.compartment_number);
}
function computeAppliedConfigHash(compartments) {
    return (0, crypto_1.createHash)('sha256')
        .update(JSON.stringify(normalizeCompartments(compartments)))
        .digest('hex');
}
