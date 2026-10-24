<template>
    <div>
       <nav class="breadcrumb" aria-label="Current page">
            <slot name="header">
               <h1>{{page}}</h1>
                    <ul v-if="folder && folder !== page">
                        <li>{{folder}}</li>
                    </ul>
            </slot>
        </nav>
        <div class="separator-breadcrumb border-top"></div>
    </div>
</template>
<script>
export default {
    props:['page','folder'],
   
}
</script>
