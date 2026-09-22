export function dup2(input) {
    const out = {}
    out.line1 = (input.line1 || "").trim()
    out.line2 = (input.line2 || "").trim()
    out.city = (input.city || "").trim()
    out.region = (input.region || "").trim()
    out.postcode = (input.postcode || "").trim().toUpperCase()
    out.country = (input.country || "GB").trim().toUpperCase()
    if (out.line1 === "") {
        throw new Error("line1 is required")
    }
    if (out.postcode === "") {
        throw new Error("postcode is required")
    }
    return out
}
