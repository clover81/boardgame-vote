<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import PasskeyVerify from '@/components/PasskeyVerify.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import RollingDicesIcon from '@/components/icons/RollingDicesIcon.vue';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

defineProps<{
    status?: string;
    canResetPassword: boolean;
}>();

const heroImage = '/images/login-estanteria-estilo-catalogo.webp';
</script>

<template>
    <Head title="Iniciar sesión" />

    <div class="min-h-svh bg-[#f7f7f5] text-[#181424] dark:bg-[#100d19] dark:text-white lg:grid lg:grid-cols-2">
        <!-- Identidad de marca en escritorio; en móvil se muestra una cabecera compacta. -->
        <!-- CL0VER-LOGIN-COMIC-2026: mismo tratamiento gráfico del catálogo, solo panel visual -->
        <aside class="relative hidden min-h-svh overflow-hidden bg-[#160a29] text-white lg:block">
            <img :src="heroImage" alt="" class="absolute inset-0 h-full w-full object-cover object-[center_40%]" />
            <!-- Degradados concentrados donde hay texto: estantería visible en los laterales. -->
            <div aria-hidden="true" class="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_72%_55%_at_50%_55%,rgba(18,5,36,0.90)_0%,rgba(21,6,43,0.71)_35%,rgba(23,7,43,0.10)_83%,transparent_100%)]" />
            <div aria-hidden="true" class="pointer-events-none absolute inset-0 bg-[linear-gradient(180deg,rgba(15,4,31,0.53)_0%,rgba(15,4,31,0.02)_24%,rgba(17,6,37,0.06)_65%,rgba(17,6,37,0.77)_100%)]" />

            <div class="relative z-10 flex min-h-svh w-full flex-col justify-between gap-5 px-8 py-8 xl:px-12 xl:py-10">
                <div class="flex w-fit items-center gap-3 rounded-2xl border border-white/10 bg-[#170a2d]/80 px-3 py-2 shadow-lg backdrop-blur-md">
                    <div class="flex size-12 shrink-0 items-center justify-center rounded-2xl border border-white/10 bg-[#4e13a8]/95 shadow-lg shadow-black/20">
                        <RollingDicesIcon class="size-8 text-[#cdd87a]" />
                    </div>
                    <div>
                        <p class="text-lg font-extrabold leading-tight tracking-tight drop-shadow-md">Juegos de mesa</p>
                        <p class="text-xs font-semibold tracking-[0.2em] text-[#cdd87a]">CLOVER</p>
                    </div>
                </div>

                <div class="mx-auto flex w-full max-w-lg flex-col items-center py-3 text-center xl:py-6">
                    <div class="relative mb-6 flex size-40 items-center justify-center rounded-[2.6rem] border border-violet-400/25 bg-[#291153]/90 shadow-[0_20px_60px_rgba(12,2,26,0.35)] xl:size-44">
                        <div aria-hidden="true" class="absolute inset-5 rounded-[2rem] border border-white/10" />
                        <RollingDicesIcon class="relative size-24 text-[#cdd87a] drop-shadow-[0_10px_12px_rgba(0,0,0,0.27)] xl:size-28" />
                    </div>
                    <div class="w-full rounded-[28px] border border-white/10 bg-[#160a2b]/80 px-5 py-6 shadow-[0_20px_55px_rgba(10,2,25,0.25)] backdrop-blur-[5px]">
                    <p class="mb-4 text-xs font-bold uppercase tracking-[0.36em] text-[#cdd87a]">Alea iacta est</p>
                    <h2 class="max-w-md text-3xl font-black leading-tight tracking-tight drop-shadow-[0_3px_14px_rgba(10,3,20,0.92)] xl:text-4xl">
                        Más juegos.<br />Más momentos juntos.
                    </h2>
                    <p class="mt-4 max-w-sm text-sm leading-relaxed text-white/85 drop-shadow-[0_2px_9px_rgba(10,3,20,0.95)]">
                        Un espacio para organizar partidas de juegos de mesa, compartir experiencias y descubrir nuevos juegos.
                    </p>
                    </div>
                </div>

                <div class="flex flex-wrap justify-center gap-2 text-xs font-semibold text-white/85">
                    <span class="rounded-full border border-white/15 bg-[#1c0a36]/75 px-3 py-1.5">Colección</span>
                    <span class="rounded-full border border-white/15 bg-[#1c0a36]/75 px-3 py-1.5">Votaciones</span>
                    <span class="rounded-full border border-white/15 bg-[#1c0a36]/75 px-3 py-1.5">Partidas</span>
                </div>
            </div>
        </aside>

        <main class="flex min-h-svh items-center justify-center px-5 py-8 sm:px-10 lg:px-14">
            <div class="w-full max-w-[440px]">
                <div class="mb-10 flex items-center gap-3 lg:hidden">
                    <div class="flex size-11 items-center justify-center rounded-xl bg-gradient-to-br from-[#8c2bff] to-[#5200FF]">
                        <RollingDicesIcon class="size-7 text-[#cdd87a]" />
                    </div>
                    <div>
                        <p class="text-base font-black leading-tight">Juegos de mesa</p>
                        <p class="text-xs font-bold tracking-[0.16em] text-[#5200FF] dark:text-[#cdd87a]">CLOVER</p>
                    </div>
                </div>

                <div class="mb-7">
                    <p class="mb-3 text-xs font-bold uppercase tracking-[0.30em] text-[#5200FF] dark:text-[#cdd87a]">Bienvenido de nuevo</p>

                    <p class="mt-3 text-sm leading-relaxed text-zinc-600 dark:text-zinc-400">Entra para organizar tu próxima partida.</p>
                </div>

                <section aria-label="Formulario de inicio de sesión" class="rounded-3xl border border-zinc-200/80 bg-white p-6 shadow-[0_16px_50px_rgba(24,20,36,0.07)] sm:p-8 dark:border-white/10 dark:bg-[#1b1628]">
                    <div v-if="status" role="status" class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-200">
                        {{ status }}
                    </div>

                    <PasskeyVerify label="Acceder con clave de acceso" loading-label="Autenticando…" separator="O entra con tu correo" />

                    <Form v-bind="store.form()" :reset-on-success="['password']" v-slot="{ errors, processing }" class="space-y-5">
                        <div class="grid gap-2">
                            <Label for="email" class="font-semibold">Correo electrónico</Label>
                            <Input
                                id="email"
                                type="email"
                                name="email"
                                required
                                v-focus
                                :tabindex="1"
                                autocomplete="email"
                                placeholder="tu@email.com"
                                class="h-11 rounded-xl border-zinc-200 bg-zinc-50 px-4 text-[#181424] placeholder:text-zinc-400 focus-visible:border-[#5200FF] focus-visible:ring-[#5200FF]/15 dark:border-white/15 dark:bg-white/5 dark:text-white dark:placeholder:text-zinc-500"
                            />
                            <InputError :message="errors.email" />
                        </div>

                        <div class="grid gap-2">
                            <div class="flex items-center justify-between gap-3">
                                <Label for="password" class="font-semibold">Contraseña</Label>
                                <a v-if="canResetPassword" :href="request().url" :tabindex="5" class="text-xs font-bold text-[#5200FF] underline-offset-4 hover:underline focus-visible:underline dark:text-[#CCFF00]">
                                    ¿Has olvidado tu contraseña?
                                </a>
                            </div>
                            <PasswordInput
                                id="password"
                                name="password"
                                required
                                :tabindex="2"
                                autocomplete="current-password"
                                placeholder="Introduce tu contraseña"
                                class="h-11 rounded-xl border-zinc-200 bg-zinc-50 px-4 text-[#181424] placeholder:text-zinc-400 focus-visible:border-[#5200FF] focus-visible:ring-[#5200FF]/15 dark:border-white/15 dark:bg-white/5 dark:text-white dark:placeholder:text-zinc-500"
                            />
                            <InputError :message="errors.password" />
                        </div>

                        <Label for="remember" class="flex cursor-pointer items-center gap-3 text-sm text-zinc-600 dark:text-zinc-300">
                            <Checkbox id="remember" name="remember" :tabindex="3" class="border-zinc-300 data-[state=checked]:border-[#5200FF] data-[state=checked]:bg-[#5200FF] dark:border-zinc-600" />
                            <span>Recordarme</span>
                        </Label>

                        <Button type="submit" :tabindex="4" :disabled="processing" data-test="login-button" class="mt-2 h-12 w-full rounded-xl bg-[#5200FF] text-sm font-extrabold text-white shadow-md shadow-[#5200FF]/20 transition hover:bg-[#4300d1] hover:text-white focus-visible:ring-[#5200FF]/35">
                            <Spinner v-if="processing" />
                            Iniciar sesión
                        </Button>
                    </Form>

                </section>

                <p class="mt-7 text-center text-xs text-zinc-500 dark:text-zinc-400">Juegos de mesa · Clover · <a href="/creditos-iconos.html" class="underline underline-offset-2 hover:text-[#5200FF] dark:hover:text-[#CCFF00]">Créditos de iconos</a></p>
            </div>
        </main>
    </div>
</template>
