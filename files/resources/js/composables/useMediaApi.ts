/* Simple composable for Media Manager HTTP calls */
export type MediaItem = {
  id: number
  disk: string
  dir: string
  path: string
  original_name: string
  mime: string
  size_bytes: number
  width?: number | null
  height?: number | null
  thumbnails?: Record<string, string>
  url: string
  thumbnails_urls?: Record<string, string>
}

export type Paginated<T> = {
  data: T[]
  current_page: number
  last_page: number
  per_page: number
  total: number
}

export function useMediaApi() {
  function csrfToken(): string | undefined {
    const meta = document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null
    return meta?.content || (window as any)?.Laravel?.csrfToken
  }
  async function list(params: { dir?: string; q?: string; page?: number; per_page?: number } = {}) {
    const usp = new URLSearchParams()
    if (params.dir) usp.set('dir', params.dir)
    if (params.q) usp.set('q', params.q)
    if (params.page) usp.set('page', String(params.page))
    if (params.per_page) usp.set('per_page', String(params.per_page))
    const res = await fetch(`/media?${usp.toString()}`, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
    if (res.status === 403) {
      let payload: any = null
      try { payload = await res.json() } catch {}
      const message = payload?.message || 'Forbidden'
      const err: any = new Error(`Failed to list media: ${message}`)
      err.code = 403
      err.payload = payload
      throw err
    }
    if (!res.ok) throw new Error(`Failed to list media: ${res.status}`)
    return (await res.json()) as Paginated<MediaItem>
  }

  async function directories() {
    const res = await fetch('/media/directories', { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
    if (!res.ok) throw new Error(`Failed to fetch directories: ${res.status}`)
    return await res.json()
  }

  async function upload(dir: string, file: File, filename?: string) {
    const fd = new FormData()
    fd.set('dir', dir)
    fd.set('file', file)
    if (filename) fd.set('filename', filename)
    const res = await fetch('/media', {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'application/json',
        ...(csrfToken() ? { 'X-CSRF-TOKEN': csrfToken()! } : {}),
      },
    })
    if (res.status === 422) {
      let payload: any = null
      try { payload = await res.json() } catch {}
      const messages: string[] = []
      if (payload?.message && typeof payload.message === 'string') messages.push(payload.message)
      if (payload?.errors && typeof payload.errors === 'object') {
        Object.values(payload.errors).forEach((arr: any) => {
          if (Array.isArray(arr) && arr.length) messages.push(String(arr[0]))
        })
      }
      const err: any = new Error(messages.join('\n') || 'Validation failed')
      err.code = 422
      err.payload = payload
      throw err
    }
    if (res.status === 409) {
      const payload = await res.json()
      const err: any = new Error(payload?.message || 'Duplicate detected')
      err.code = 409
      err.payload = payload
      throw err
    }
    if (res.status === 403) {
      let payload: any = null
      try { payload = await res.json() } catch {}
      const message = payload?.message || 'Forbidden'
      const err: any = new Error(message)
      err.code = 403
      err.payload = payload
      throw err
    }
    if (!res.ok) throw new Error(`Upload failed: ${res.status}`)
    return (await res.json()) as MediaItem
  }

  async function createFolder(parent_dir: string, name: string) {
    const fd = new FormData()
    fd.set('parent_dir', parent_dir)
    fd.set('name', name)
    const res = await fetch('/media/folders', {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'application/json',
        ...(csrfToken() ? { 'X-CSRF-TOKEN': csrfToken()! } : {}),
      },
    })
    if (res.status === 422) {
      let payload: any = null
      try { payload = await res.json() } catch {}
      const messages: string[] = []
      if (payload?.message && typeof payload.message === 'string') messages.push(payload.message)
      if (payload?.errors && typeof payload.errors === 'object') {
        Object.values(payload.errors).forEach((arr: any) => {
          if (Array.isArray(arr) && arr.length) messages.push(String(arr[0]))
        })
      }
      const err: any = new Error(messages.join('\n') || 'Validation failed')
      err.code = 422
      err.payload = payload
      throw err
    }
    if (res.status === 403) {
      let payload: any = null
      try { payload = await res.json() } catch {}
      const message = payload?.message || 'Forbidden'
      const err: any = new Error(message)
      err.code = 403
      err.payload = payload
      throw err
    }
    if (!res.ok) throw new Error(`Create folder failed: ${res.status}`)
    return await res.json()
  }

  return { list, directories, upload, createFolder }
}
