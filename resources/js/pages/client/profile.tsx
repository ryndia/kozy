import { Head, Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { apiGet, apiPatch, extractErrorMessage } from '@/lib/api';
import client from '@/routes/client';
import { edit as editSecurity } from '@/routes/security';

type ClientProfile = {
    id: number;
    name: string;
    email: string;
    phone?: string | null;
    role?: string;
    created_at?: string | null;
};

export default function ProfilePage() {
    const [profile, setProfile] = useState<ClientProfile | null>(null);
    const [form, setForm] = useState({ name: '', email: '', phone: '' });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        apiGet('/api/me')
            .then((response) => {
                setProfile(response.user);
                setForm({
                    name: response.user?.name ?? '',
                    email: response.user?.email ?? '',
                    phone: response.user?.phone ?? '',
                });
            })
            .catch((error) => toast.error(extractErrorMessage(error)));
    }, []);

    const save = async () => {
        setSaving(true);
        setErrors({});

        try {
            const response = await apiPatch('/api/me', form);
            setProfile(response.user);
            toast.success(response.message ?? 'Profile updated.');
        } catch (error) {
            const validation = (error as { errors?: Record<string, string[]> })
                ?.errors;

            if (validation) {
                setErrors(
                    Object.fromEntries(
                        Object.entries(validation).map(([field, messages]) => [
                            field,
                            messages[0],
                        ]),
                    ),
                );
            }

            toast.error(extractErrorMessage(error));
        } finally {
            setSaving(false);
        }
    };

    return (
        <>
            <Head title="Profile" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div>
                    <p className="text-xs tracking-[0.22em] text-muted-foreground uppercase">
                        Client area
                    </p>
                    <h1 className="mt-2 text-3xl font-semibold tracking-tight">
                        Profile
                    </h1>
                    <p className="mt-2 text-sm text-muted-foreground">
                        Keep your details current so hosts can reach you about
                        your stays.
                    </p>
                </div>

                <div className="grid gap-4 lg:grid-cols-[1.2fr_0.8fr]">
                    <section className="rounded-xl border border-border bg-card p-6 shadow-sm">
                        {profile ? (
                            <div className="grid gap-5">
                                <div className="grid gap-2">
                                    <Label htmlFor="name">Full name</Label>
                                    <Input
                                        id="name"
                                        value={form.name}
                                        onChange={(event) =>
                                            setForm((current) => ({
                                                ...current,
                                                name: event.target.value,
                                            }))
                                        }
                                        autoComplete="name"
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="email">Email address</Label>
                                    <Input
                                        id="email"
                                        type="email"
                                        value={form.email}
                                        onChange={(event) =>
                                            setForm((current) => ({
                                                ...current,
                                                email: event.target.value,
                                            }))
                                        }
                                        autoComplete="email"
                                    />
                                    <InputError message={errors.email} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="phone">Phone number</Label>
                                    <Input
                                        id="phone"
                                        value={form.phone}
                                        onChange={(event) =>
                                            setForm((current) => ({
                                                ...current,
                                                phone: event.target.value,
                                            }))
                                        }
                                        autoComplete="tel"
                                        placeholder="+230 5 123 4567"
                                    />
                                    <InputError message={errors.phone} />
                                </div>

                                <div>
                                    <Button
                                        onClick={save}
                                        disabled={
                                            saving ||
                                            !form.name.trim() ||
                                            !form.email.trim()
                                        }
                                    >
                                        {saving ? 'Saving…' : 'Save profile'}
                                    </Button>
                                </div>
                            </div>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                Loading your profile…
                            </p>
                        )}
                    </section>

                    <section className="space-y-4">
                        <div className="rounded-xl border border-border bg-card p-6 shadow-sm">
                            <div className="text-xs tracking-[0.18em] text-muted-foreground uppercase">
                                Account
                            </div>
                            <dl className="mt-4 space-y-3 text-sm">
                                <div className="flex justify-between gap-3">
                                    <dt className="text-muted-foreground">
                                        Account type
                                    </dt>
                                    <dd className="font-medium capitalize">
                                        {profile?.role ?? '—'}
                                    </dd>
                                </div>
                                <div className="flex justify-between gap-3">
                                    <dt className="text-muted-foreground">
                                        Member since
                                    </dt>
                                    <dd className="font-medium">
                                        {profile?.created_at
                                            ? new Date(
                                                  profile.created_at,
                                              ).toLocaleDateString(undefined, {
                                                  month: 'short',
                                                  year: 'numeric',
                                              })
                                            : '—'}
                                    </dd>
                                </div>
                            </dl>
                        </div>

                        <div className="rounded-xl border border-border bg-card p-6 shadow-sm">
                            <div className="text-xs tracking-[0.18em] text-muted-foreground uppercase">
                                Shortcuts
                            </div>
                            <div className="mt-4 flex flex-col gap-2">
                                <Button variant="outline" asChild>
                                    <Link href={client.bookings()}>
                                        My bookings
                                    </Link>
                                </Button>
                                <Button variant="outline" asChild>
                                    <Link href={editSecurity()}>
                                        Password &amp; security
                                    </Link>
                                </Button>
                            </div>
                        </div>
                    </section>
                </div>
            </div>
        </>
    );
}

ProfilePage.layout = {
    breadcrumbs: [
        {
            title: 'Profile',
            href: client.profile(),
        },
    ],
};
