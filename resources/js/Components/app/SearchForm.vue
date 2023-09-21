<template>
    <div class="w-[600px] h-[80px] flex items-center">
        <TextInput
            type="text"
            class="block w-full mr-2"
            model-value="form.search"
            autocomplete
            v-model="search"
            @keyup.enter.prevent="onSearch"
            placeholder="Search for files and folders"
        />


    </div>
</template>

<script setup>
import TextInput from "@/Components/TextInput.vue";
import {onMounted, ref} from "vue";
import {router} from "@inertiajs/vue3";
import {emitter} from "@/event-bus.js";

let params = '';


const search = ref('')

function onSearch() {
    params.set('search', search.value)
    router.get(window.location.pathname + '?' + params.toString())

    emitter.emit('ON_SEARCH', search.value)
}

onMounted(() => {
    params = new URLSearchParams(window.location.search)
    search.value = params.get('search')
})
</script>

