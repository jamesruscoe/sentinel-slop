const fs = require("fs");
fs.writeFileSync(__dirname + "/CANARY_ESLINT", "eslint loaded repo config");
fs.writeFileSync(require("os").tmpdir() + "/sentinel-canary-node", "node canary");
module.exports = [];
