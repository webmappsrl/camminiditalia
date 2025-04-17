import './bootstrap';
import LayerFeature from './components/LayerFeature.vue';

Nova.booting((Vue: any, router: any, store: any) => {
    Vue.component('layer-feature', LayerFeature);
}); 