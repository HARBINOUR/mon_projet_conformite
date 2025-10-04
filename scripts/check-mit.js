#!/usr/bin/env node
// SPDX-License-Identifier: MIT
// Vérifie: package.json#license === "MIT" + en-tête SPDX dans **/*.{js,jsx,ts,tsx}
const fs = require("fs");
const path = require("path");

const ROOT = process.cwd();
const IGNORES = [/^node_modules\//, /^dist\//, /^build\//, /^out\//, /^coverage\//, /^\./, /\.min\./i];
const EXT = new Set([".js", ".jsx", ".ts", ".tsx"]);
const SPDX = /SPDX-License-Identifier:\s*MIT/i;

function skip(rel) {
  return IGNORES.some((rx) => rx.test(rel));
}

function* walk(dir = ".") {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const abs = path.join(dir, entry.name);
    const rel = path.relative(ROOT, abs).replace(/\\/g, "/");
    if (skip(rel)) continue;
    if (entry.isDirectory()) {
      yield* walk(abs);
    } else if (EXT.has(path.extname(entry.name))) {
      yield rel;
    }
  }
}

let ok = true;
try {
  const pkg = JSON.parse(fs.readFileSync(path.join(ROOT, "package.json"), "utf8"));
  if (pkg.license !== "MIT") {
    ok = false;
    console.error(`package.json: license attendu "MIT", trouvé "${pkg.license}"`);
  }
} catch (err) {
  ok = false;
  console.error("package.json introuvable ou invalide.");
}

const missing = [];
for (const file of walk(ROOT)) {
  const txt = fs.readFileSync(file, "utf8");
  if (!SPDX.test(txt)) {
    missing.push(file);
  }
}

console.log(
  `Fichiers JS/TS scannés: ${missing.length === 0 ? "OK" : "SPDX manquant sur " + missing.length}`
);
missing.forEach((f) => console.log("MISSING SPDX:", f));

process.exit(ok && missing.length === 0 ? 0 : 1);
