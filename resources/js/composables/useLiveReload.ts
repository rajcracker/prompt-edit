import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, watch, type Ref } from 'vue';

/**
 * While `active` is true, reload the given Inertia props every `interval` ms so
 * statuses (and the balance) update live. Stops once nothing is in flight.
 */
export function useLiveReload(active: Ref<boolean>, only: string[], interval = 2500) {
    let timer: ReturnType<typeof setTimeout> | undefined;

    const tick = () => {
        timer = setTimeout(() => {
            router.reload({ only: [...only, 'auth'], onFinish: () => active.value && tick() });
        }, interval);
    };

    watch(
        active,
        (on) => {
            clearTimeout(timer);
            if (on) tick();
        },
        { immediate: true },
    );

    onBeforeUnmount(() => clearTimeout(timer));
}
