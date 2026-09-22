export function cleanAddress(input: Record<string, string>) {
    const out: Record<string, string> = {}
    out.line1 = (input.line1 || '').trim()
    out.line2 = (input.line2 || '').trim()
    out.city = (input.city || '').trim()
    out.region = (input.region || '').trim()
    out.postcode = (input.postcode || '').trim().toUpperCase()
    out.country = (input.country || 'GB').trim().toUpperCase()
    if (out.line1 === '') {
        throw new Error('line1 is required')
    }
    if (out.postcode === '') {
        throw new Error('postcode is required')
    }
    return out
}
