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
</script>

<template>
    <Head title="Iniciar sesión" />

    <div class="min-h-svh bg-[#f7f7f5] text-[#181424] dark:bg-[#100d19] dark:text-white lg:grid lg:grid-cols-2">
        <!-- Identidad de marca en escritorio; en móvil se muestra una cabecera compacta. -->
        <aside class="relative hidden min-h-svh flex-col justify-between overflow-hidden bg-[#181424] p-10 text-white lg:flex xl:p-16">
            <div aria-hidden="true" class="pointer-events-none absolute -left-32 top-1/3 size-96 rounded-full bg-[#5200FF]/30 blur-3xl" />
            <div aria-hidden="true" class="pointer-events-none absolute -right-40 bottom-0 size-[30rem] rounded-full bg-[#5200FF]/35 blur-3xl" />

            <div class="relative z-10 flex items-center gap-3">
                <div class="flex size-12 items-center justify-center rounded-2xl bg-[#5200FF] shadow-lg shadow-black/20">
                    <RollingDicesIcon class="size-8 text-[#CCFF00]" />
                </div>
                <div>
                    <p class="text-lg font-extrabold leading-tight tracking-tight">Juegos de mesa</p>
                    <p class="text-xs font-semibold tracking-[0.2em] text-[#CCFF00]">CLOVER</p>
                </div>
            </div>

            <div class="relative z-10 mx-auto flex w-full max-w-lg flex-col items-center py-10 text-center">
                <div class="relative mb-12 flex size-64 items-center justify-center rounded-[3.5rem] border border-white/10 bg-[#5200FF]/20 shadow-[0_24px_80px_rgba(0,0,0,0.25)] xl:size-80">
                    <div aria-hidden="true" class="absolute inset-6 rounded-[3rem] border border-[#CCFF00]/10" />
                    <RollingDicesIcon class="relative size-44 text-[#CCFF00] drop-shadow-[0_18px_14px_rgba(0,0,0,0.25)] xl:size-56" />
                </div>
                <p class="mb-4 text-xs font-bold uppercase tracking-[0.50em] text-[#CCFF00]">Alea iacta est</p>
                <h2 class="max-w-md text-4xl font-black leading-tight tracking-tight xl:text-5xl">Más juegos.<br />Más momentos juntos.</h2>
                <p class="mt-4 text-sm leading-relaxed text-white/70">Un espacio para organizar partidas de juegos de mesa, compartir experiencias y descubrir nuevos juegos.</p>
            </div>

            <div class="relative z-10 flex flex-wrap justify-center gap-x-6 gap-y-2 text-xs font-semibold text-white/55">
                <span>Colección</span>
                <span>Votaciones</span>
                <span>Partidas</span>
            </div>
        </aside>

        <main class="flex min-h-svh items-center justify-center px-5 py-8 sm:px-10 lg:px-14">
            <div class="w-full max-w-[440px]">
                <div class="mb-10 flex items-center gap-3 lg:hidden">
                    <div class="flex size-11 items-center justify-center rounded-xl bg-[#5200FF]">
                        <RollingDicesIcon class="size-7 text-[#CCFF00]" />
                    </div>
                    <div>
                        <p class="text-base font-black leading-tight">Juegos de mesa</p>
                        <p class="text-xs font-bold tracking-[0.16em] text-[#5200FF] dark:text-[#CCFF00]">CLOVER</p>
                    </div>
                </div>

                <div class="mb-7">
                    <p class="mb-3 text-xs font-bold uppercase tracking-[0.30em] text-[#5200FF] dark:text-[#CCFF00]">Bienvenido de nuevo</p>

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
