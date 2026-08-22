import { Head } from '@inertiajs/react';
import { REGEXP_ONLY_DIGITS } from 'input-otp';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { apiPost, extractErrorMessage } from '@/lib/api';
import { login } from '@/routes';

type Step = 'details' | 'otp' | 'password';

/**
 * Sign-up flow for new clients: name + email, one-time code, then the password
 * screen. When the email already belongs to an account we ask for the password
 * instead of sending a code, so signing up never overwrites existing credentials.
 */
export default function ClientAuthFlow() {
    const [step, setStep] = useState<Step>('details');
    const [name, setName] = useState('');
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [otp, setOtp] = useState('');
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const intended = useMemo(() => {
        const params = new URLSearchParams(window.location.search);

        return params.get('redirect') || '/client/bookings';
    }, []);

    const description = useMemo(() => {
        if (step === 'otp') {
            return `Enter the 6-digit code sent to ${email}. Next you will choose a password.`;
        }

        if (step === 'password') {
            return `${email} already has an account. Enter your password to continue.`;
        }

        return 'Enter your name and email. We will send a one-time code to verify you before you set your password.';
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

            setStep('otp');
            toast.success('OTP sent to your email.');
        } catch (err) {
            handleFailure(err);
        } finally {
            setProcessing(false);
        }
    };

    const handleVerify = async () => {
        setProcessing(true);
        setError(null);

        try {
            const response = await apiPost('/client/auth/verify', { otp });
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
        setOtp('');
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
                            Send OTP
                        </Button>
                    </div>
                ) : null}

                {step === 'otp' ? (
                    <div className="space-y-4">
                        <div className="flex flex-col items-center justify-center space-y-3 text-center">
                            <div className="flex w-full items-center justify-center">
                                <InputOTP
                                    maxLength={6}
                                    value={otp}
                                    onChange={(value) => setOtp(value)}
                                    disabled={processing}
                                    pattern={REGEXP_ONLY_DIGITS}
                                    autoFocus
                                >
                                    <InputOTPGroup>
                                        {Array.from(
                                            { length: 6 },
                                            (_, index) => (
                                                <InputOTPSlot
                                                    key={index}
                                                    index={index}
                                                />
                                            ),
                                        )}
                                    </InputOTPGroup>
                                </InputOTP>
                            </div>
                            <InputError message={error ?? undefined} />
                        </div>

                        <Button
                            type="button"
                            className="w-full"
                            onClick={handleVerify}
                            disabled={processing || otp.length !== 6}
                        >
                            {processing && <Spinner />}
                            Verify OTP
                        </Button>

                        <button
                            type="button"
                            onClick={restart}
                            className="w-full text-sm text-muted-foreground underline-offset-4 hover:underline"
                        >
                            Use a different email
                        </button>
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
