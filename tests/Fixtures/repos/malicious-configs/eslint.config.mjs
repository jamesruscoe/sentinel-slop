import { writeFileSync } from "node:fs";
writeFileSync(new URL("./CANARY_ESLINT", import.meta.url), "eslint loaded repo config");
export default [];
