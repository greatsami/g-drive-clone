<template>
    <span class="w-6 h-6 inline-flex justify-center items-center mr-2">
        <svg v-if="file.is_folder" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6">
            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 014.5 9.75h15A2.25 2.25 0 0121.75 12v.75m-8.69-6.44l-2.12-2.12a1.5 1.5 0 00-1.061-.44H4.5A2.25 2.25 0 002.25 6v12a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9a2.25 2.25 0 00-2.25-2.25h-5.379a1.5 1.5 0 01-1.06-.44z" />
        </svg>
        <template v-else>
            <img class="max-w-full" v-if="isImage(file)" :src="`/images/icons/image.png`" alt="Image" />
            <img class="max-w-full" v-else-if="isPDF(file)" :src="`/images/icons/pdf.png`" alt="PDF" />
            <img class="max-w-full" v-else-if="isAudio(file)" :src="`/images/icons/audio.png`" alt="Audio" />
            <img class="max-w-full" v-else-if="isVideo(file)" :src="`/images/icons/video.png`" alt="Video" />
            <img class="max-w-full" v-else-if="isWord(file)" :src="`/images/icons/word.png`" alt="Word" />
            <img class="max-w-full" v-else-if="isExcel(file)" :src="`/images/icons/excel.png`" alt="Excel" />
            <img class="max-w-full" v-else-if="isZip(file)" :src="`/images/icons/zip.png`" alt="zip" />
            <img class="max-w-full" v-else-if="isText(file)" :src="`/images/icons/txt-file.png`" alt="Text file" />
            <img class="max-w-full" v-else :src="`/images/icons/attach-file.png`" alt="File" />
        </template>
    </span>

</template>

<script setup>
import {isAudio, isExcel, isImage, isPDF, isText, isVideo, isWord, isZip} from "@/Helper/file-helper.js";

const  {file} = defineProps({
    file: Object
})
</script>
