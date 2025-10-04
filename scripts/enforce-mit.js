#!/usr/bin/env node
// SPDX-License-Identifier: MIT
/* Enforce "MIT" in package.json + add SPDX headers in JS/TS files */
const fs = require("fs");
const path = require("path");

const IGNORES = [/^node_modules\//, /^dist\//, /^build\//, /^out\//, /^coverage\//, /^\./, /\.min\./i];
const EXT = new Set([".js", ".jsx", ".ts", ".tsx"]);
const SPDX = /SPDX-License-Identifier:\s*MIT/i;

function shouldSkip(p) {
  return IGNORES.some((rx) => rx.test(p));
}

function walk(dir) {
  const out = [];
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const abs = path.join(dir, entry.name);
    const rel = path.relative(process.cwd(), abs).replace(/\\/g, "/");
    if (shouldSkip(rel)) continue;
    if (entry.isDirectory()) {
      out.push(...walk(abs));
    } else if (EXT.has(path.extname(entry.name))) {
      out.push(rel);
    }
  }
  return out;
}

(function enforcePackage() {
  const pkgPath = path.join(process.cwd(), "package.json");
  const pkg = JSON.parse(fs.readFileSync(pkgPath, "utf8"));
  if (pkg.license !== "MIT") {
    pkg.license = "MIT";
    fs.writeFileSync(pkgPath, `${JSON.stringify(pkg, null, 2)}\n`);
  }
})();

const changed = [];
for (const file of walk(process.cwd())) {
  const txt = fs.readFileSync(file, "utf8");
  if (SPDX.test(txt)) continue;
  const lines = txt.split(/\r?\n/);
  if (lines[0]?.startsWith("#!")) {
    lines.splice(1, 0, "// SPDX-License-Identifier: MIT", "");
  } else {
    lines.unshift("// SPDX-License-Identifier: MIT", "");
  }
  fs.writeFileSync(file, lines.join("\n"));
  changed.push(file);
}

const pkg = JSON.parse(fs.readFileSync("package.json", "utf8"));
console.log(`package.json → "license": "${pkg.license}"`);
console.log(`Fichiers balisés SPDX (+${changed.length})`);
for (const file of changed) {
  console.log("  -", file);
}
