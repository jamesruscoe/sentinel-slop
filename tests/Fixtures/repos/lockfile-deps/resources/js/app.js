import { createApp } from 'vue';
import axios from 'axios';
import { renderToString } from '@vue/server-renderer';
import leftPad from 'left-pad-fake';

createApp({}).mount('#app');
axios.get('/ping').then(() => renderToString(leftPad('x', 2)));
