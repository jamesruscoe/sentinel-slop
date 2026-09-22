const fs = require("fs");
fs.writeFileSync(__dirname + "/CANARY_JSCPD", "jscpd loaded repo reporter");
module.exports = { report() {} };
