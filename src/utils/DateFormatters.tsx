export const DateFormatters = {
  formatDate: (date: string) => {
    const dt = new Date(date);
    const options: Intl.DateTimeFormatOptions = {
      day: "numeric",
      month: "short",
      year: "numeric",
    };
    return dt.toLocaleString("en-us", options);
  },
};
