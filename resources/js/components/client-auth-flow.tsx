import { Head } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { apiPost, extractErrorMessage } from '@/lib/api';
import { login } from '@/routes';

type Step = 'details' | 'password';

/**
 * Sign-up flow for new clients: name + email, then straight on to choosing a
 * password and paying. When the email already belongs to an account we ask for
 * that password instead, so signing up never overwrites existing credentials.
 */
export default function ClientAuthFlow() {
    const [step, setStep] = useState<Step>('details');
    const [name, setName] = useState('');
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const intended = useMemo(() => {
        const params = new URLSearchParams(window.location.search);

        return params.get('redirect') || '/client/bookings';
    }, []);

    const description = useMemo(() => {
        if (step === 'password') {
            return `${email} already has an account. Enter your password to continue.`;
        }

        return 'Enter your name and email, then choose a password to finish your booking.';
    }, [email, step]);

    const handleFailure = (err: unknown) => {
        const message = extractErrorMessage(err);
        setError(message);
        toast.error(message);
    };

    const handleStart = async () => {
        setProcessing(true);
        setError(null);

        try {
            const response = await apiPost('/client/auth/start', {
                name,
                email,
                intended,
            });

            if (response.account_exists) {
                setStep('password');
                toast.info(
                    response.message ?? 'This email already has an account.',
                );

                return;
            }

            // A new guest goes straight to choosing a password - no code, no
            // detour through their inbox mid-booking.
            window.location.href =
                response.redirect_url || '/client/auth/password';
        } catch (err) {
            handleFailure(err);
        } finally {
            setProcessing(false);
        }
    };

    const handleLogin = async () => {
        setProcessing(true);
        setError(null);

        try {
            const response = await apiPost('/client/auth/login', {
                email,
                password,
                intended,
            });
            window.location.href = response.redirect_url || '/client/bookings';
        } catch (err) {
            handleFailure(err);
        } finally {
            setProcessing(false);
        }
    };

    const restart = () => {
        setStep('details');
        setPassword('');
        setError(null);
    };

    return (
        <>
            <Head title="Create your account" />

            <div className="flex flex-col gap-6">
                <div className="space-y-2 text-center">
                    <p className="text-sm font-medium tracking-[0.18em] text-muted-foreground uppercase">
                        Client access
                    </p>
                    <h1 className="text-2xl font-semibold text-foreground">
                        Create your client account
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {description}
                    </p>
                </div>

                {step === 'details' ? (
                    <div className="grid gap-6">
                        <div className="grid gap-2">
                            <Label htmlFor="name">Name</Label>
                            <Input
                                id="name"
                                type="text"
                                required
                                autoFocus
                                autoComplete="name"
                                value={name}
                                onChange={(event) =>
                                    setName(event.target.value)
                                }
                                placeholder="Full name"
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="email">Email address</Label>
                            <Input
                                id="email"
                                type="email"
                                required
                                autoComplete="email"
                                value={email}
                                onChange={(event) =>
                                    setEmail(event.target.value)
                                }
                                placeholder="email@example.com"
                            />
                        </div>

                        <InputError message={error ?? undefined} />

                        <Button
                            type="button"
                            className="w-full"
                            onClick={handleStart}
                            disabled={
                                processing || !name.trim() || !email.trim()
                            }
                        >
                            {processing && <Spinner />}
                            Continue
                        </Button>
                    </div>
                ) : null}

                {step === 'password' ? (
                    <div className="grid gap-6">
                        <div className="grid gap-2">
                            <Label htmlFor="existing-password">Password</Label>
                            <PasswordInput
                                id="existing-password"
                                required
                                autoFocus
                                autoComplete="current-password"
                                value={password}
                                onChange={(event) =>
                                    setPassword(event.target.value)
                                }
                                placeholder="Your password"
                            />
                        </div>

                        <InputError message={error ?? undefined} />

                        <Button
                            type="button"
                            className="w-full"
                            onClick={handleLogin}
                            disabled={processing || !password}
                        >
                            {processing && <Spinner />}
                            Log in and continue
                        </Button>

                        <div className="flex items-center justify-between text-sm text-muted-foreground">
                            <button
                                type="button"
                                onClick={restart}
                                className="underline-offset-4 hover:underline"
                            >
                                Use a different email
                            </button>
                            <TextLink href="/forgot-password">
                                Forgot password?
                            </TextLink>
                        </div>
                    </div>
                ) : null}

                <div className="text-center text-sm text-muted-foreground">
                    Already have an account?{' '}
                    <TextLink href={login()}>Log in</TextLink>
                </div>
            </div>
        </>
    );
}
