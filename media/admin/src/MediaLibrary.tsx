import { setQuery, useLocation, type SurfaceComponentProps } from '@letterncms/sdk'
import { useEffect, useState } from 'react'
import { Library } from './Library'

/** The library of the Media place (MediaPlugin::places): its Upload is the page's. `?upload=1` opens the file picker. */
export default function MediaLibrary(_: SurfaceComponentProps) {
  const { query } = useLocation()
  const [upload] = useState(query.upload === '1')
  useEffect(() => { if (query.upload) setQuery({ upload: null }) }, [query.upload])
  return <Library mode="manage" bar autoUpload={upload} />
}
