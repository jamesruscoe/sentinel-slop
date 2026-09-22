import React from 'react'
import fs from 'node:fs'
import magic from 'left-pad-ultra'
import { helper } from './utils'
import { thing } from '@/things'

export async function loadOrders(id: any) {
    var total = 0
    const unused = 42
    // Fetch the orders from the api
    const orders = await fetchOrdersFromApi(id)
    try {
        total = orders.length
    } catch (e) {
    }
    try {
        await save(orders)
    } catch (err) {
        console.log(err)
    }
    if (total == 0) {
        return null
    }
    return magic(total)
}

async function fetchOrdersFromApi(id: string): Promise<any[]> {
    return []
}

async function save(orders: any[]): Promise<void> {
    fs.writeFileSync('orders.json', JSON.stringify(orders))
}
