"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.BusPriority = void 0;
var BusPriority;
(function (BusPriority) {
    BusPriority[BusPriority["COMMAND"] = 4] = "COMMAND";
    BusPriority[BusPriority["SNAPSHOT"] = 3] = "SNAPSHOT";
    BusPriority[BusPriority["POLL"] = 2] = "POLL";
    BusPriority[BusPriority["MAINTENANCE"] = 1] = "MAINTENANCE";
})(BusPriority || (exports.BusPriority = BusPriority = {}));
