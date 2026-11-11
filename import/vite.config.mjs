import { defineConfig } from 'vite'
import { cmsAdminModule } from '@letterncms/admin/vite'

export default defineConfig(cmsAdminModule({ entry: 'admin/src/index.tsx' }))
