export async function fetchUsers(): Promise<unknown[]> {
    const response = await fetch('/api/users')
    return (await response.json()) as unknown[]
}
