import React, { useEffect, useMemo, useRef, useState } from 'react';
import { Loader2Icon, MapPin, Search } from 'lucide-react';
import type { PublicProperty } from '@/lib/property-search';

type DestinationAutocompleteProps = {
    value: string;
    onChange: (value: string) => void;
    availableProperties?: PublicProperty[];
    placeholder?: string;
    inputClassName?: string;
    panelClassName?: string;
};

type DestinationSuggestion = {
    id: string;
    label: string;
    caption: string;
    value: string;
    type: 'property' | 'place';
};

type NominatimResult = {
    place_id: number;
    name?: string;
    display_name?: string;
    address?: {
        city?: string;
        town?: string;
        village?: string;
        municipality?: string;
        county?: string;
        state?: string;
        country?: string;
    };
};

const buildPlaceSearchValue = (result: NominatimResult) => {
    const address = result.address ?? {};

    return (
        address.city ??
        address.town ??
        address.village ??
        address.municipality ??
        result.name ??
        result.display_name ??
        ''
    );
};

const buildPlaceLabel = (result: NominatimResult) => {
    return (
        result.name ||
        buildPlaceSearchValue(result) ||
        result.display_name ||
        'Suggested place'
    );
};

export default function DestinationAutocomplete({
    value,
    onChange,
    availableProperties = [],
    placeholder = 'Title, city, or address',
    inputClassName,
    panelClassName,
}: DestinationAutocompleteProps) {
    const rootRef = useRef<HTMLDivElement | null>(null);
    const [open, setOpen] = useState(false);
    const [highlightedIndex, setHighlightedIndex] = useState(-1);
    const [placeSuggestions, setPlaceSuggestions] = useState<
        DestinationSuggestion[]
    >([]);
    const [loadingPlaces, setLoadingPlaces] = useState(false);

    const propertySuggestions = useMemo(() => {
        const query = value.trim().toLowerCase();
        const suggestions: DestinationSuggestion[] = [];
        const seen = new Set<string>();

        for (const property of availableProperties) {
            const title = (property.title || '').trim();
            const address = (property.address || '').trim();
            const searchable = `${title} ${address}`.toLowerCase();

            if (query && !searchable.includes(query)) {
                continue;
            }

            const searchValue = address || title;

            if (!searchValue) {
                continue;
            }

            const uniqueKey = `${title}|${address}|${searchValue}`;

            if (seen.has(uniqueKey)) {
                continue;
            }

            seen.add(uniqueKey);
            suggestions.push({
                id: `property-${property.id ?? property.slug ?? uniqueKey}`,
                label: title || searchValue,
                caption: address || 'Available stay',
                value: searchValue,
                type: 'property',
            });

            if (suggestions.length >= 5) {
                break;
            }
        }

        return suggestions;
    }, [availableProperties, value]);

    useEffect(() => {
        const query = value.trim();

        if (query.length < 2) {
            setPlaceSuggestions([]);
            setLoadingPlaces(false);
            return;
        }

        const controller = new AbortController();
        const timeout = window.setTimeout(() => {
            setLoadingPlaces(true);

            fetch(
                `https://nominatim.openstreetmap.org/search?format=jsonv2&addressdetails=1&limit=5&q=${encodeURIComponent(query)}`,
                {
                    signal: controller.signal,
                    headers: {
                        Accept: 'application/json',
                    },
                },
            )
                .then((response) => response.json())
                .then((results: NominatimResult[]) => {
                    const suggestions: DestinationSuggestion[] = Array.isArray(
                        results,
                    )
                        ? results.reduce<DestinationSuggestion[]>(
                              (items, result) => {
                                  const suggestionValue =
                                      buildPlaceSearchValue(result);

                                  if (!suggestionValue) {
                                      return items;
                                  }

                                  items.push({
                                      id: `place-${result.place_id}`,
                                      label: buildPlaceLabel(result),
                                      caption:
                                          result.display_name ||
                                          suggestionValue,
                                      value: suggestionValue,
                                      type: 'place',
                                  });

                                  return items;
                              },
                              [],
                          )
                        : [];

                    setPlaceSuggestions(suggestions);
                })
                .catch((error) => {
                    if (error?.name !== 'AbortError') {
                        setPlaceSuggestions([]);
                    }
                })
                .finally(() => setLoadingPlaces(false));
        }, 250);

        return () => {
            controller.abort();
            window.clearTimeout(timeout);
        };
    }, [value]);

    useEffect(() => {
        const handlePointerDown = (event: MouseEvent) => {
            if (!rootRef.current?.contains(event.target as Node)) {
                setOpen(false);
                setHighlightedIndex(-1);
            }
        };

        document.addEventListener('mousedown', handlePointerDown);

        return () =>
            document.removeEventListener('mousedown', handlePointerDown);
    }, []);

    const suggestions = useMemo(() => {
        const merged = [...propertySuggestions];
        const existingValues = new Set(
            propertySuggestions.map((suggestion) =>
                suggestion.value.toLowerCase(),
            ),
        );

        for (const suggestion of placeSuggestions) {
            if (existingValues.has(suggestion.value.toLowerCase())) {
                continue;
            }

            merged.push(suggestion);
        }

        return merged.slice(0, 8);
    }, [placeSuggestions, propertySuggestions]);

    const selectSuggestion = (suggestion: DestinationSuggestion) => {
        onChange(suggestion.value);
        setOpen(false);
        setHighlightedIndex(-1);
    };

    const handleKeyDown = (event: React.KeyboardEvent<HTMLInputElement>) => {
        if (!suggestions.length) {
            return;
        }

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setOpen(true);
            setHighlightedIndex(
                (current) => (current + 1) % suggestions.length,
            );
        }

        if (event.key === 'ArrowUp') {
            event.preventDefault();
            setOpen(true);
            setHighlightedIndex((current) =>
                current <= 0 ? suggestions.length - 1 : current - 1,
            );
        }

        if (event.key === 'Enter' && open && highlightedIndex >= 0) {
            event.preventDefault();
            selectSuggestion(suggestions[highlightedIndex]);
        }

        if (event.key === 'Escape') {
            setOpen(false);
            setHighlightedIndex(-1);
        }
    };

    return (
        <div ref={rootRef} className="relative">
            <input
                type="text"
                value={value}
                onChange={(event) => {
                    onChange(event.target.value);
                    setOpen(true);
                    setHighlightedIndex(-1);
                }}
                onFocus={() => setOpen(true)}
                onKeyDown={handleKeyDown}
                placeholder={placeholder}
                autoComplete="off"
                className={inputClassName}
            />

            {open && (suggestions.length > 0 || loadingPlaces) ? (
                <div
                    className={
                        panelClassName ||
                        // Wider than the field it hangs off: place captions are
                        // long ("Servoz, Haute-Savoie, Auvergne-Rhone-Alpes,
                        // France") and unreadable squeezed into a narrow column.
                        'absolute top-[calc(100%+0.5rem)] left-0 z-30 w-full max-w-[calc(100vw_-_2rem)] overflow-hidden rounded-2xl border border-border bg-card shadow-[0_18px_50px_rgba(15,23,42,0.12)] sm:min-w-[28rem]'
                    }
                >
                    <div className="max-h-[26rem] overflow-y-auto p-2">
                        {suggestions.map((suggestion, index) => {
                            const active = index === highlightedIndex;

                            return (
                                <button
                                    key={suggestion.id}
                                    type="button"
                                    onMouseEnter={() =>
                                        setHighlightedIndex(index)
                                    }
                                    onMouseDown={(event) =>
                                        event.preventDefault()
                                    }
                                    onClick={() => selectSuggestion(suggestion)}
                                    className={`flex w-full items-start gap-3 rounded-xl px-4 py-3 text-left transition ${active ? 'bg-muted' : 'bg-transparent hover:bg-accent'}`}
                                >
                                    <span
                                        className={`mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-full ${suggestion.type === 'property' ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300' : 'bg-sky-50 text-sky-700 dark:bg-sky-950/50 dark:text-sky-300'}`}
                                    >
                                        {suggestion.type === 'property' ? (
                                            <Search className="h-4.5 w-4.5" />
                                        ) : (
                                            <MapPin className="h-4.5 w-4.5" />
                                        )}
                                    </span>
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate text-base font-medium text-foreground">
                                            {suggestion.label}
                                        </span>
                                        <span className="mt-0.5 line-clamp-2 block text-sm leading-5 break-words text-muted-foreground">
                                            {suggestion.caption}
                                        </span>
                                    </span>
                                    <span
                                        className={`mt-0.5 shrink-0 rounded-full px-2 py-1 text-[10px] font-semibold tracking-[0.14em] uppercase ${suggestion.type === 'property' ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300' : 'bg-sky-50 text-sky-700 dark:bg-sky-950/50 dark:text-sky-300'}`}
                                    >
                                        {suggestion.type === 'property'
                                            ? 'Stay'
                                            : 'Place'}
                                    </span>
                                </button>
                            );
                        })}

                        {loadingPlaces ? (
                            <div className="flex items-center gap-2 px-4 py-3 text-sm text-muted-foreground">
                                <Loader2Icon className="h-4 w-4 animate-spin" />
                                Searching places...
                            </div>
                        ) : null}
                    </div>
                </div>
            ) : null}
        </div>
    );
}
