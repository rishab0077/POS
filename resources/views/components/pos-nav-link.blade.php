@props(['active'])

@php
    $classes =
        $active ?? false
            ? 'm-1 px-2 py-2 text-sm text-grey-900 font-semibold whitespace-nowrap bg-gray-200 rounded-lg hover:text-gray-900 focus:text-gray-900 hover:bg-gray-200 focus:bg-gray-200 focus:outline-none focus:shadow-outline sm:m-2 sm:px-4'
            : 'm-1 px-2 py-2 text-sm text-white font-semibold whitespace-nowrap bg-transparent rounded-lg hover:text-gray-900 focus:text-gray-900 hover:bg-gray-200 focus:bg-gray-200 focus:outline-none focus:shadow-outline sm:m-2 sm:px-4';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
