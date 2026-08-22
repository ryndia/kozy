import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { apiPost, extractErrorMessage } from '@/lib/api';

type Props = {
    email: string;
    passwordRules: string;
};

export default function SetPassword({ email, passwordRules }: Props) {
    const [password, setPassword] = useState('');
    const [passwordConfirmation, setPasswordConfirmation] = useState('');
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const handleSubmit = async () => {
        setProcessing(true);
        setError(null);

        try {
            const response = await apiPost('/client/auth/password', {
                password,
                password_confirmation: passwordConfirmation,
            });
            window.location.href = response.redirect_url || '/client/bookings';
        } catch (err) {
            const message = extractErrorMessage(err);
            setError(message);
            toast.error(message);
        } finally {
            setProcessing(false);
        }
    };

    return (
        <>
            <Head title="Set your password" />

            <div className="flex flex-col gap-6">
                <div className="space-y-2 text-center">
                    <p className="text-sm font-medium tracking-[0.18em] text-muted-foreground uppercase">
                        Client access
                    </p>
                    <h1 className="text-2xl font-semibold text-foreground">
                        Set your password
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Verified for{' '}
                        <span className="font-medium text-foreground">
                            {email}
                        </span>
                        . Set your password to finish accessing the website.
                    </p>
                </div>

                <div className="grid gap-6">
                    <div className="grid gap-2">
                        <Label htmlFor="password">Password</Label>
                        <PasswordInput
                            id="password"
                            required
                            autoFocus
                            autoComplete="new-password"
                            value={password}
                            onChange={(event) =>
                                setPassword(event.target.value)
                            }
                            placeholder="Set password"
                            passwordrules={passwordRules}
                        />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="password_confirmation">
                            Confirm password
                        </Label>
                        <PasswordInput
                            id="password_confirmation"
                            required
                            autoComplete="new-password"
                            value={passwordConfirmation}
                            onChange={(event) =>
                                setPasswordConfirmation(event.target.value)
                            }
                            placeholder="Confirm password"
                            passwordrules={passwordRules}
                        />
                    </div>

                    <InputError message={error ?? undefined} />

                    <Button
                        type="button"
                        className="w-full"
                        onClick={handleSubmit}
                        disabled={
                            processing || !password || !passwordConfirmation
                        }
                    >
                        {processing && <Spinner />}
                        Save password and continue
                    </Button>
                </div>
            </div>
        </>
    );
}

SetPassword.layout = {
    title: 'Set your password',
    description:
        'Finish verifying your email, choose a password, and access your client area.',
};
