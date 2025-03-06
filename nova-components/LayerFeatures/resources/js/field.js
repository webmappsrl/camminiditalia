import IndexField from './components/IndexField'
import DetailField from './components/DetailField'
import FormField from './components/FormField'
import PreviewField from './components/PreviewField'

Nova.booting((app, store) => {
  app.component('index-layer-features', IndexField)
  app.component('detail-layer-features', DetailField)
  app.component('form-layer-features', FormField)
  // app.component('preview-layer-features', PreviewField)
})
