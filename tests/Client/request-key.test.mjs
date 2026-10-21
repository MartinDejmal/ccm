import assert from "node:assert/strict";
import { webcrypto } from "node:crypto";
import { readFileSync } from "node:fs";
import { test } from "node:test";
import vm from "node:vm";
import ts from "typescript";

const source = readFileSync(
    new URL("../../resources/js/lib/request-key.ts", import.meta.url),
    "utf8",
);
const code = ts.transpileModule(source, {
    compilerOptions: {
        module: ts.ModuleKind.CommonJS,
        target: ts.ScriptTarget.ES2022,
    },
}).outputText;
function load(crypto) {
    const context = vm.createContext({ crypto, exports: {} });
    vm.runInContext(code, context);
    return context.exports.createRequestKey;
}

test("uses native randomUUID when available", () => {
    const expected = "92b8a82e-a3f0-409f-b589-646a7886d8fa";
    assert.equal(load({ randomUUID: () => expected })(), expected);
});

test("HTTP fallback creates distinct RFC 4122 version 4 UUIDs without randomUUID", () => {
    const create = load({
        getRandomValues: webcrypto.getRandomValues.bind(webcrypto),
    });
    const keys = Array.from({ length: 100 }, create);
    for (const key of keys) {
        assert.match(
            key,
            /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/,
        );
    }
    assert.equal(new Set(keys).size, keys.length);
});
